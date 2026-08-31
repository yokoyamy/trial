<?php
declare(strict_types=1);

/**
 * ============================================================
 * 自作AI Prototype
 * ============================================================
 *
 * 前提:
 *   - Apache + PHP
 *   - データベースなし
 *   - 外部APIなし
 *   - index.php 1ファイル
 *
 * 現段階:
 *   ルールベースのモックAI
 *
 * 将来的に:
 *   記憶 / 知識 / 検索 / AI API / ローカルLLM
 *   などへ段階的に置き換える。
 * ============================================================
 */


/* ============================================================
 * 初期設定
 * ============================================================ */

session_start();

header('Content-Type: text/html; charset=UTF-8');


/* ============================================================
 * セッション初期化
 * ============================================================ */

if (!isset($_SESSION['ai_messages']) || !is_array($_SESSION['ai_messages'])) {
    $_SESSION['ai_messages'] = [];
}


/* ============================================================
 * 共通関数
 * ============================================================ */

/**
 * HTMLエスケープ
 */
function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/**
 * 会話履歴へ追加
 */
function addMessage(string $role, string $content): void
{
    $_SESSION['ai_messages'][] = [
        'role' => $role,
        'content' => $content,
        'time' => date('H:i:s')
    ];
}


/**
 * 文字列にキーワードが含まれているか確認
 */
function containsAny(string $text, array $keywords): bool
{
    foreach ($keywords as $keyword) {
        if ($keyword !== '' && mb_stripos($text, $keyword, 0, 'UTF-8') !== false) {
            return true;
        }
    }

    return false;
}


/**
 * 質問らしい文章か
 */
function isQuestion(string $text): bool
{
    $questionKeywords = [
        '？',
        '?',
        'なぜ',
        'どうして',
        'どうやって',
        '教えて',
        'とは',
        '何',
        'できますか',
        'できる？',
        'できます？',
        '方法',
        '理由'
    ];

    return containsAny($text, $questionKeywords);
}


/* ============================================================
 * AI本体
 * ============================================================ */

/**
 * ------------------------------------------------------------
 * 自作AIモック
 * ------------------------------------------------------------
 *
 * 現時点では「ルールベースAI」。
 *
 * 重要:
 * この関数が将来のAIエンジンとの交換ポイントになる。
 *
 * 例えば将来的に、
 *
 *   $result = callOpenAI(...);
 *
 *   $result = callLocalLLM(...);
 *
 * などに置き換えられる。
 * ------------------------------------------------------------
 */
function think(string $userMessage, array $history): string
{
    $text = trim($userMessage);


    /* --------------------------------------------------------
     * 空入力
     * -------------------------------------------------------- */

    if ($text === '') {
        return "何か話しかけてください。";
    }


    /* --------------------------------------------------------
     * あいさつ
     * -------------------------------------------------------- */

    if (containsAny($text, [
        'こんにちは',
        'こんばんは',
        'おはよう',
        'おはようございます',
        'やあ',
        'hello',
        'Hello',
        'HELLO',
        'hi',
        'Hi'
    ])) {
        return
            "こんにちは！\n\n" .
            "私は今作っている自作AIのプロトタイプです。\n" .
            "現在はまだルールベースのモックですが、ここから少しずつ賢くしていきます。";
    }


    /* --------------------------------------------------------
     * 自己紹介
     * -------------------------------------------------------- */

    if (containsAny($text, [
        'あなたは誰',
        'あなたは何',
        '誰ですか',
        '何者',
        '自己紹介',
        'どんなAI',
        'どんなＡＩ'
    ])) {
        return
            "私は「自作AI」の実験用プロトタイプです。\n\n" .
            "現在はApache＋PHPだけで動作しています。\n" .
            "データベースや外部AI APIはまだ使用していません。\n\n" .
            "最初は単純なモックとして動かし、そこから記憶・知識・検索・推論などを追加していく予定です。";
    }


    /* --------------------------------------------------------
     * AIについて
     * -------------------------------------------------------- */

    if (containsAny($text, [
        'AI',
        'ＡＩ',
        '人工知能',
        '機械学習',
        '生成AI',
        '生成ＡＩ'
    ])) {
        return
            "AIを自作する場合、いきなり巨大なAIモデルを作る必要はありません。\n\n" .
            "まずは、\n" .
            "1. 入力を受け取る\n" .
            "2. 状況を判断する\n" .
            "3. 回答を生成する\n" .
            "4. 会話を記憶する\n" .
            "という基本構造を作れます。\n\n" .
            "このindex.phpは、その最初の実験場です。";
    }


    /* --------------------------------------------------------
     * PHPについて
     * -------------------------------------------------------- */

    if (containsAny($text, [
        'PHP',
        'ｐｈｐ',
        'Apache',
        'サーバー',
        'プログラム',
        'プログラミング'
    ])) {
        return
            "PHPだけでも、自作AIのプロトタイプは作れます。\n\n" .
            "今はデータベースを使わず、PHPのセッションに会話履歴を保持しています。\n\n" .
            "次の段階では、テキストファイルを「知識」として扱うこともできます。";
    }


    /* --------------------------------------------------------
     * 記憶について
     * -------------------------------------------------------- */

    if (containsAny($text, [
        '覚えて',
        '記憶',
        '覚える',
        '忘れ',
        'メモリー',
        'memory'
    ])) {
        return
            "現在、この会話中のメッセージはPHPのセッションに保存されています。\n\n" .
            "つまり簡易的な「短期記憶」はあります。\n\n" .
            "次の段階では、データベースを使わずにテキストファイルへ記憶を保存する仕組みも追加できます。";
    }


    /* --------------------------------------------------------
     * データベースについて
     * -------------------------------------------------------- */

    if (containsAny($text, [
        'データベース',
        'DB',
        'database',
        'SQL'
    ])) {
        return
            "このプロトタイプではデータベースを使わない方針です。\n\n" .
            "必要になった場合でも、最初はJSONやTXTファイルを利用できます。\n\n" .
            "その後、本格的な知識量になったらデータベースへ移行する方法があります。";
    }


    /* --------------------------------------------------------
     * 作り方について
     * -------------------------------------------------------- */

    if (containsAny($text, [
        '作り方',
        '作る',
        '自作',
        '開発',
        '実装',
        '作って',
        '作りたい'
    ])) {
        return
            "いいですね。\n\n" .
            "このAIは、最初から完成品を作るのではなく、少しずつ機能を増やしていく方針にできます。\n\n" .
            "まずは「会話」→「記憶」→「知識」→「検索」→「推論」→「AIモデル」という順番で発展させるのが分かりやすいです。";
    }


    /* --------------------------------------------------------
     * 質問
     * -------------------------------------------------------- */

    if (isQuestion($text)) {
        return
            "質問を受け取りました。\n\n" .
            "ただし、私は現在まだ本物の生成AIには接続されていません。\n" .
            "今は入力された文章をルールベースで判断しています。\n\n" .
            "この部分を少しずつ高度な「思考エンジン」に置き換えていくことができます。";
    }


    /* --------------------------------------------------------
     * 会話履歴を利用した簡易応答
     * -------------------------------------------------------- */

    $messageCount = count($history);

    if ($messageCount >= 6) {
        return
            "会話が少し続いてきましたね。\n\n" .
            "現在、私は過去のメッセージをセッションに保持しています。\n" .
            "会話数は " . $messageCount . " 件です。\n\n" .
            "将来的には、この履歴から重要な情報を抽出して「長期記憶」にできます。";
    }


    /* --------------------------------------------------------
     * デフォルト応答
     * -------------------------------------------------------- */

    $responses = [
        "なるほど。「" . $text . "」についてですね。\n\nもう少し詳しく教えてもらえれば、そこから考えてみます。",
        "「" . $text . "」という入力を受け取りました。\n\n現在はモックAIなので、これから判断能力を追加していく段階です。",
        "面白いですね。\n\n私はまだ簡単なルールベースAIですが、この入力をきっかけに新しい処理を追加できます。",
        "入力を理解しました。\n\n今後この部分を強化して、単純なキーワード判定ではなく、文脈を考えて回答できるようにしていきましょう。"
    ];

    return $responses[array_rand($responses)];
}


/* ============================================================
 * POST処理
 * ============================================================ */

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* --------------------------------------------------------
     * 会話リセット
     * -------------------------------------------------------- */

    if (isset($_POST['reset'])) {

        $_SESSION['ai_messages'] = [];

        // 同じページへ戻す
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }


    /* --------------------------------------------------------
     * メッセージ
     * -------------------------------------------------------- */

    if (isset($_POST['message'])) {

        $userMessage = trim((string)$_POST['message']);

        if ($userMessage === '') {

            $error = 'メッセージを入力してください。';

        } else {

            /*
             * ユーザー発言を先に保存
             */
            addMessage('user', $userMessage);

            /*
             * 現在までの履歴をAIへ渡す
             */
            $history = $_SESSION['ai_messages'];

            /*
             * AI思考
             */
            $aiResponse = think($userMessage, $history);

            /*
             * AI回答を保存
             */
            addMessage('ai', $aiResponse);
        }
    }
}


/* ============================================================
 * 画面表示用データ
 * ============================================================ */

$messages = $_SESSION['ai_messages'];

?>
<!DOCTYPE html>
<html lang="ja">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>自作AI Prototype</title>

<style>

* {
    box-sizing: border-box;
}

html,
body {
    width: 100%;
    height: 100%;
    margin: 0;
}

body {
    background: #f4f6f8;
    color: #1f2937;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Hiragino Kaku Gothic ProN",
        "Yu Gothic",
        Meiryo,
        sans-serif;
}


/* ============================================================
   アプリ
   ============================================================ */

.app {
    width: 100%;
    max-width: 960px;
    height: 100vh;
    height: 100dvh;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    background: #ffffff;
}


/* ============================================================
   ヘッダー
   ============================================================ */

.header {
    flex: 0 0 auto;

    min-height: 68px;

    padding: 12px 20px;

    display: flex;
    align-items: center;
    justify-content: space-between;

    border-bottom: 1px solid #e5e7eb;

    background: #ffffff;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.ai-logo {
    width: 42px;
    height: 42px;

    border-radius: 12px;

    display: flex;
    align-items: center;
    justify-content: center;

    background: linear-gradient(
        135deg,
        #2563eb,
        #7c3aed
    );

    color: #ffffff;

    font-weight: bold;
}

.title {
    font-size: 17px;
    font-weight: 700;
}

.subtitle {
    margin-top: 2px;

    color: #6b7280;

    font-size: 11px;
}

.status {
    color: #16a34a;
}


/* ============================================================
   リセット
   ============================================================ */

.reset-button {
    padding: 8px 12px;

    border: 1px solid #d1d5db;
    border-radius: 8px;

    background: #ffffff;

    color: #374151;

    cursor: pointer;

    font-size: 13px;
}

.reset-button:hover {
    background: #f9fafb;
}


/* ============================================================
   チャット
   ============================================================ */

.chat {
    flex: 1;

    min-height: 0;

    overflow-y: auto;

    padding: 28px 20px;
}


/* ============================================================
   ウェルカム
   ============================================================ */

.welcome {
    max-width: 650px;

    margin: 50px auto;

    text-align: center;
}

.welcome-icon {
    width: 70px;
    height: 70px;

    margin: 0 auto 20px;

    border-radius: 20px;

    display: flex;
    align-items: center;
    justify-content: center;

    background: linear-gradient(
        135deg,
        #2563eb,
        #7c3aed
    );

    color: #ffffff;

    font-size: 28px;
}

.welcome h1 {
    margin: 0 0 12px;

    font-size: 28px;
}

.welcome p {
    margin: 7px 0;

    color: #6b7280;

    line-height: 1.7;
}


/* ============================================================
   メッセージ
   ============================================================ */

.message {
    display: flex;

    margin-bottom: 22px;
}

.message.user {
    justify-content: flex-end;
}

.message.ai {
    justify-content: flex-start;
}

.message-content {
    display: flex;

    max-width: 82%;
}

.message.user .message-content {
    flex-direction: row-reverse;
}


/* ============================================================
   アイコン
   ============================================================ */

.message-icon {
    width: 34px;
    height: 34px;

    flex: 0 0 34px;

    margin: 2px 10px 0;

    border-radius: 50%;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 11px;
    font-weight: bold;
}

.ai-icon {
    background: #111827;
    color: #ffffff;
}

.user-icon {
    background: #dbeafe;
    color: #1d4ed8;
}


/* ============================================================
   バブル
   ============================================================ */

.bubble {
    padding: 13px 16px;

    border-radius: 16px;

    line-height: 1.75;

    white-space: pre-wrap;

    overflow-wrap: anywhere;

    font-size: 14px;
}

.ai .bubble {
    background: #f1f5f9;

    border-bottom-left-radius: 5px;

    color: #1f2937;
}

.user .bubble {
    background: #2563eb;

    border-bottom-right-radius: 5px;

    color: #ffffff;
}


/* ============================================================
   エラー
   ============================================================ */

.error {
    margin: 0 auto 15px;

    padding: 10px 14px;

    max-width: 650px;

    border: 1px solid #fecaca;
    border-radius: 8px;

    background: #fef2f2;

    color: #b91c1c;

    font-size: 13px;
}


/* ============================================================
   入力
   ============================================================ */

.input-area {
    flex: 0 0 auto;

    padding: 14px 20px 18px;

    border-top: 1px solid #e5e7eb;

    background: #ffffff;
}

.input-form {
    display: flex;

    gap: 10px;

    max-width: 900px;

    margin: 0 auto;
}

.message-input {
    flex: 1;

    min-width: 0;

    min-height: 48px;
    max-height: 150px;

    padding: 13px 15px;

    resize: vertical;

    border: 1px solid #cbd5e1;

    border-radius: 12px;

    outline: none;

    font-family: inherit;
    font-size: 15px;

    line-height: 1.5;
}

.message-input:focus {
    border-color: #2563eb;

    box-shadow:
        0 0 0 3px rgba(37, 99, 235, .10);
}

.send-button {
    flex: 0 0 78px;

    border: 0;

    border-radius: 12px;

    background: #2563eb;

    color: #ffffff;

    cursor: pointer;

    font-size: 14px;
    font-weight: 600;
}

.send-button:hover {
    background: #1d4ed8;
}

.input-note {
    max-width: 900px;

    margin: 7px auto 0;

    color: #9ca3af;

    font-size: 10px;

    text-align: center;
}


/* ============================================================
   スマートフォン
   ============================================================ */

@media (max-width: 600px) {

    .header {
        padding: 10px 12px;
    }

    .title {
        font-size: 15px;
    }

    .subtitle {
        font-size: 10px;
    }

    .reset-button {
        padding: 7px 9px;

        font-size: 11px;
    }

    .chat {
        padding: 20px 10px;
    }

    .message-content {
        max-width: 92%;
    }

    .message-icon {
        margin-left: 5px;
        margin-right: 5px;
    }

    .bubble {
        font-size: 14px;
    }

    .input-area {
        padding: 10px;
    }

    .send-button {
        flex-basis: 62px;
    }

    .welcome {
        margin: 30px auto;
    }

    .welcome h1 {
        font-size: 23px;
    }
}

</style>

</head>


<body>


<div class="app">


    <!-- ======================================================
         ヘッダー
         ====================================================== -->

    <header class="header">

        <div class="header-left">

            <div class="ai-logo">
                AI
            </div>

            <div>

                <div class="title">
                    自作AI
                </div>

                <div class="subtitle">
                    <span class="status">●</span>
                    PHP Mock Engine / Databaseなし
                </div>

            </div>

        </div>


        <form method="post">

            <button
                type="submit"
                name="reset"
                value="1"
                class="reset-button"
            >
                会話をリセット
            </button>

        </form>

    </header>


    <!-- ======================================================
         チャット
         ====================================================== -->

    <main
        class="chat"
        id="chat"
    >


        <?php if (empty($messages)): ?>

            <div class="welcome">

                <div class="welcome-icon">
                    AI
                </div>

                <h1>
                    自作AIへようこそ
                </h1>

                <p>
                    Apache＋PHPだけで動作するAIプロトタイプです。
                </p>

                <p>
                    データベースも外部APIも使用していません。
                </p>

                <p>
                    下の入力欄から話しかけてみてください。
                </p>

            </div>

        <?php endif; ?>


        <?php if ($error !== ''): ?>

            <div class="error">
                <?= e($error) ?>
            </div>

        <?php endif; ?>


        <?php foreach ($messages as $message): ?>

            <?php
                $role = isset($message['role'])
                    ? $message['role']
                    : 'ai';

                $content = isset($message['content'])
                    ? $message['content']
                    : '';
            ?>


            <?php if ($role === 'user'): ?>

                <div class="message user">

                    <div class="message-content">

                        <div class="message-icon user-icon">
                            YOU
                        </div>

                        <div class="bubble">
                            <?= e($content) ?>
                        </div>

                    </div>

                </div>

            <?php else: ?>

                <div class="message ai">

                    <div class="message-content">

                        <div class="message-icon ai-icon">
                            AI
                        </div>

                        <div class="bubble">
                            <?= e($content) ?>
                        </div>

                    </div>

                </div>

            <?php endif; ?>

        <?php endforeach; ?>


    </main>


    <!-- ======================================================
         入力
         ====================================================== -->

    <section class="input-area">

        <form
            method="post"
            class="input-form"
            autocomplete="off"
        >

            <textarea
                name="message"
                class="message-input"
                placeholder="AIに話しかけてください..."
                aria-label="メッセージ"
                required
                autofocus
            ></textarea>

            <button
                type="submit"
                class="send-button"
            >
                送信
            </button>

        </form>


        <div class="input-note">
            Ctrl + Enter / Command + Enter でも送信できます
        </div>

    </section>


</div>


<script>

/*
 * ============================================================
 * チャットを最新位置へ
 * ============================================================
 */

(function () {

    const chat = document.getElementById('chat');

    if (chat) {
        chat.scrollTop = chat.scrollHeight;
    }

})();


/*
 * ============================================================
 * Ctrl + Enter / Command + Enter
 * ============================================================
 */

(function () {

    const textarea =
        document.querySelector('.message-input');

    if (!textarea) {
        return;
    }

    textarea.addEventListener('keydown', function (event) {

        if (
            (event.ctrlKey || event.metaKey) &&
            event.key === 'Enter'
        ) {
            event.preventDefault();

            if (this.form) {
                this.form.submit();
            }
        }

    });

})();


/*
 * ============================================================
 * 送信時の二重送信防止
 * ============================================================
 */

(function () {

    const form =
        document.querySelector('.input-form');

    const button =
        document.querySelector('.send-button');

    if (!form || !button) {
        return;
    }

    form.addEventListener('submit', function () {

        button.disabled = true;

        button.textContent = '処理中...';

    });

})();

</script>


</body>

</html>

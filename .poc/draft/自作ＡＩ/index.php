<?php
session_start();

/*
 * ============================================================
 *  AI Mock - index.php
 *  Apache + PHP / Database不要
 * ============================================================
 */

// 会話履歴をセッションに保存
if (!isset($_SESSION['messages'])) {
    $_SESSION['messages'] = [];
}

// 会話リセット
if (isset($_POST['reset'])) {
    $_SESSION['messages'] = [];
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// メッセージ送信
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {

    $userMessage = trim($_POST['message']);

    if ($userMessage !== '') {

        // ユーザー発言を保存
        $_SESSION['messages'][] = [
            'role' => 'user',
            'content' => $userMessage
        ];

        /*
         * ----------------------------------------------------
         * ここが「AI本体」のモック
         *
         * 将来的にはここを
         * OpenAI API / Gemini API / ローカルAI
         * などに置き換える。
         * ----------------------------------------------------
         */
        $aiMessage = generateMockResponse($userMessage);

        // AI回答を保存
        $_SESSION['messages'][] = [
            'role' => 'ai',
            'content' => $aiMessage
        ];
    }

    // POST再送信防止
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}


/**
 * モックAI
 */
function generateMockResponse($message)
{
    $message = trim($message);

    // あいさつ
    if (preg_match('/こんにちは|こんばんは|おはよう|やあ|hello|hi/i', $message)) {
        return "こんにちは！\n私はPHPで作ったAIモックです。\n\nまだ本物のAIではありませんが、ここから少しずつ賢くしていきましょう。";
    }

    // 自己紹介
    if (preg_match('/あなた.*誰|あなた.*何|自己紹介/i', $message)) {
        return "私はApache＋PHPだけで動いているAIモックです。\n\n現在はデータベースも外部AI APIも使っていません。";
    }

    // AIについて
    if (preg_match('/AI|人工知能/i', $message)) {
        return "AIを自作する第一歩として、まずは会話システムを作っています。\n\n今はルールベースですが、後からAI APIや独自モデルに接続できます。";
    }

    // PHPについて
    if (preg_match('/PHP|プログラム|プログラミング/i', $message)) {
        return "PHPなら、データベースなしでも簡単なAIモックを作れます。\n\nまずは「入力 → 処理 → 応答」という基本構造を作り、その後で記憶・検索・AIモデルなどを追加していくのがおすすめです。";
    }

    // 質問
    if (preg_match('/なぜ|どうして|理由|教えて|とは|何ですか|できますか/u', $message)) {
        return "面白い質問ですね。\n\n現在の私は簡単なルールで回答しています。\n\n入力された文章を解析して、条件に合った回答を返す仕組みです。";
    }

    // デフォルト
    $responses = [
        "なるほど。もう少し詳しく教えてください。",
        "面白いですね。その内容について考えてみます。",
        "現在はモックAIなので、まだ簡単な応答しかできません。",
        "その質問には、これからAI機能を追加するともっと詳しく答えられるようになります。",
        "入力を受け取りました。ここから私を少しずつ賢くしていきましょう。"
    ];

    return $responses[array_rand($responses)];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>My AI</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f5f7fb;
    color: #222;
    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        "Hiragino Kaku Gothic ProN",
        "Yu Gothic",
        Meiryo,
        sans-serif;
}

/* 全体 */
.app {
    max-width: 900px;
    height: 100vh;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    background: #fff;
}

/* ヘッダー */
.header {
    height: 64px;
    padding: 0 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid #e5e7eb;
    background: #ffffff;
}

.logo {
    font-size: 20px;
    font-weight: bold;
}

.status {
    font-size: 12px;
    color: #16a34a;
}

.reset {
    border: 1px solid #ddd;
    background: #fff;
    border-radius: 8px;
    padding: 7px 12px;
    cursor: pointer;
}

.reset:hover {
    background: #f3f4f6;
}

/* チャット */
.chat {
    flex: 1;
    overflow-y: auto;
    padding: 30px 20px;
}

/* メッセージ */
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

.bubble {
    max-width: 75%;
    padding: 14px 17px;
    border-radius: 16px;
    line-height: 1.7;
    white-space: pre-wrap;
}

.user .bubble {
    background: #2563eb;
    color: white;
    border-bottom-right-radius: 5px;
}

.ai .bubble {
    background: #f1f5f9;
    color: #222;
    border-bottom-left-radius: 5px;
}

/* AIアイコン */
.ai-icon {
    width: 34px;
    height: 34px;
    margin-right: 10px;
    border-radius: 50%;
    background: #111827;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    flex-shrink: 0;
}

/* 初期画面 */
.welcome {
    text-align: center;
    padding: 80px 20px;
}

.welcome h1 {
    margin-bottom: 10px;
}

.welcome p {
    color: #6b7280;
}

/* 入力エリア */
.input-area {
    padding: 15px 20px;
    border-top: 1px solid #e5e7eb;
    background: #fff;
}

.input-form {
    display: flex;
    gap: 10px;
}

.input-form textarea {
    flex: 1;
    min-height: 48px;
    max-height: 150px;
    resize: vertical;
    border: 1px solid #d1d5db;
    border-radius: 12px;
    padding: 13px 14px;
    font-size: 15px;
    font-family: inherit;
    outline: none;
}

.input-form textarea:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}

.send {
    width: 80px;
    border: none;
    border-radius: 12px;
    background: #2563eb;
    color: white;
    font-size: 15px;
    cursor: pointer;
}

.send:hover {
    background: #1d4ed8;
}

/* スマホ */
@media (max-width: 600px) {

    .app {
        height: 100dvh;
    }

    .bubble {
        max-width: 88%;
    }

    .header {
        padding: 0 12px;
    }

    .chat {
        padding: 20px 12px;
    }

    .input-area {
        padding: 10px;
    }

    .send {
        width: 60px;
    }
}
</style>

</head>

<body>

<div class="app">

    <!-- ヘッダー -->
    <header class="header">

        <div>
            <div class="logo">🤖 My AI</div>
            <div class="status">● Online / Mock AI</div>
        </div>

        <form method="post">
            <button
                type="submit"
                name="reset"
                class="reset">
                会話をリセット
            </button>
        </form>

    </header>


    <!-- チャット -->
    <main class="chat" id="chat">

        <?php if (empty($_SESSION['messages'])): ?>

            <div class="welcome">

                <h1>こんにちは 👋</h1>

                <p>
                    PHPで作った自作AIのプロトタイプです。
                </p>

                <p>
                    何か話しかけてみてください。
                </p>

            </div>

        <?php else: ?>

            <?php foreach ($_SESSION['messages'] as $message): ?>

                <?php if ($message['role'] === 'user'): ?>

                    <div class="message user">

                        <div class="bubble">
                            <?= htmlspecialchars(
                                $message['content'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </div>

                    </div>

                <?php else: ?>

                    <div class="message ai">

                        <div class="ai-icon">
                            AI
                        </div>

                        <div class="bubble">
                            <?= htmlspecialchars(
                                $message['content'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </div>

                    </div>

                <?php endif; ?>

            <?php endforeach; ?>

        <?php endif; ?>

    </main>


    <!-- 入力 -->
    <div class="input-area">

        <form
            method="post"
            class="input-form">

            <textarea
                name="message"
                placeholder="メッセージを入力してください..."
                required
                autofocus></textarea>

            <button
                type="submit"
                class="send">
                送信
            </button>

        </form>

    </div>

</div>

<script>
// チャットを一番下へ
const chat = document.getElementById('chat');

if (chat) {
    chat.scrollTop = chat.scrollHeight;
}

// Ctrl + Enter / Command + Enter で送信
const textarea = document.querySelector('textarea');

if (textarea) {
    textarea.addEventListener('keydown', function(e) {

        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            this.form.submit();
        }

    });
}
</script>

</body>
</html>

<?php
declare(strict_types=1);

/**
 * ============================================================
 * 自作AI Prototype
 * 第2段階：会話履歴を利用した文脈エンジン
 * ============================================================
 *
 * 環境：
 *   Apache + PHP
 *
 * 制約：
 *   - データベースなし
 *   - 外部AI APIなし
 *   - index.php 1ファイル
 *
 * 第2段階の目的：
 *
 *   「現在の入力だけを見る」のではなく、
 *   過去の会話から現在の発言の意味を推定する。
 *
 * 例：
 *
 *   ユーザー：
 *   PHPでAIを作りたい
 *
 *   AI：
 *   まずモックから始めましょう。
 *
 *   ユーザー：
 *   それを詳しく教えて
 *
 *   ↓
 *
 *   「それ」が直前の話題を指していると推定する。
 *
 * ============================================================
 */


/* ============================================================
 * 基本設定
 * ============================================================ */

session_start();

header('Content-Type: text/html; charset=UTF-8');


/* ============================================================
 * セッション初期化
 * ============================================================ */

if (!isset($_SESSION['ai_messages']) || !is_array($_SESSION['ai_messages'])) {
    $_SESSION['ai_messages'] = [];
}


/*
 * 文脈情報
 *
 * 会話履歴とは別に、
 * 現在AIが把握している「会話状態」を保持する。
 */
if (!isset($_SESSION['ai_context']) || !is_array($_SESSION['ai_context'])) {
    $_SESSION['ai_context'] = [
        'topic' => '',
        'topic_score' => 0,
        'last_subject' => '',
        'last_intent' => '',
        'turn' => 0
    ];
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
 * キーワードを含むか
 *
 * 日本語を考慮して mb_stripos() を使用する。
 */
function containsAny(string $text, array $keywords): bool
{
    foreach ($keywords as $keyword) {

        if ($keyword === '') {
            continue;
        }

        if (mb_stripos($text, $keyword, 0, 'UTF-8') !== false) {
            return true;
        }
    }

    return false;
}


/**
 * 文字列から不要な空白を整理
 */
function normalizeText(string $text): string
{
    $text = trim($text);

    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

    return $text;
}


/**
 * 日本語テキストを安全に短縮
 */
function shortenText(string $text, int $length = 80): string
{
    $text = trim($text);

    if (mb_strlen($text, 'UTF-8') <= $length) {
        return $text;
    }

    return mb_substr($text, 0, $length, 'UTF-8') . '…';
}


/* ============================================================
 * 会話履歴
 * ============================================================ */


/**
 * 最新のユーザー発言を取得
 */
function getLastUserMessage(array $history): string
{
    for ($i = count($history) - 1; $i >= 0; $i--) {

        if (
            isset($history[$i]['role']) &&
            $history[$i]['role'] === 'user'
        ) {
            return (string)$history[$i]['content'];
        }
    }

    return '';
}


/**
 * 最新のAI回答を取得
 */
function getLastAiMessage(array $history): string
{
    for ($i = count($history) - 1; $i >= 0; $i--) {

        if (
            isset($history[$i]['role']) &&
            $history[$i]['role'] === 'ai'
        ) {
            return (string)$history[$i]['content'];
        }
    }

    return '';
}


/**
 * 過去のユーザー発言を取得
 */
function getPreviousUserMessage(
    array $history,
    string $currentMessage
): string {

    $foundCurrent = false;

    for ($i = count($history) - 1; $i >= 0; $i--) {

        if (
            !isset($history[$i]['role']) ||
            $history[$i]['role'] !== 'user'
        ) {
            continue;
        }

        $content = (string)$history[$i]['content'];

        /*
         * 現在の発言は除外する。
         */
        if (!$foundCurrent && $content === $currentMessage) {
            $foundCurrent = true;
            continue;
        }

        return $content;
    }

    return '';
}


/**
 * 直近の会話を取得
 *
 * AIが文脈を判断するための短期記憶。
 */
function getRecentConversation(
    array $history,
    int $maxMessages = 8
): array {

    if (count($history) <= $maxMessages) {
        return $history;
    }

    return array_slice(
        $history,
        -$maxMessages
    );
}


/* ============================================================
 * 話題解析
 * ============================================================ */


/**
 * 話題辞書
 *
 * 「単語があれば即回答」という用途ではない。
 *
 * 現在の会話が何について話している可能性が高いかを
 * 推定するために使用する。
 */
function getTopicDictionary(): array
{
    return [

        'PHP' => [
            'PHP',
            'php',
            'PHPコード',
            'PHPで',
            'PHPの'
        ],

        'AI' => [
            'AI',
            'ＡＩ',
            '人工知能',
            '生成AI',
            '生成ＡＩ',
            'LLM',
            'モデル'
        ],

        '自作AI' => [
            '自作AI',
            '自作ＡＩ',
            'AIを作る',
            'ＡＩを作る',
            'AI開発',
            'AI開発'
        ],

        'Apache' => [
            'Apache',
            'apache',
            'Webサーバー',
            'ウェブサーバー'
        ],

        'データベース' => [
            'データベース',
            'database',
            'DB',
            'ＤＢ',
            'SQL'
        ],

        'プログラミング' => [
            'プログラミング',
            'プログラム',
            'コード',
            '実装',
            '開発'
        ],

        '文脈' => [
            '文脈',
            '会話履歴',
            '会話',
            'コンテキスト',
            'context'
        ],

        '記憶' => [
            '記憶',
            '覚えて',
            '覚える',
            '忘れ',
            'メモリー',
            'memory'
        ]
    ];
}


/**
 * 現在の発言から話題候補を抽出
 */
function detectTopics(string $text): array
{
    $dictionary = getTopicDictionary();

    $scores = [];

    foreach ($dictionary as $topic => $keywords) {

        $score = 0;

        foreach ($keywords as $keyword) {

            if (
                mb_stripos(
                    $text,
                    $keyword,
                    0,
                    'UTF-8'
                ) !== false
            ) {
                $score++;
            }
        }

        if ($score > 0) {
            $scores[$topic] = $score;
        }
    }

    arsort($scores);

    return $scores;
}


/**
 * 会話履歴から現在の話題を推定
 *
 * 現在の入力だけでなく、
 * 過去の発言も加味する。
 */
function detectContextTopic(
    string $currentMessage,
    array $history,
    array $previousContext
): array {

    $scores = [];

    /*
     * --------------------------------------------------------
     * 1. 現在の発言
     * --------------------------------------------------------
     */

    $currentTopics = detectTopics($currentMessage);

    foreach ($currentTopics as $topic => $score) {

        if (!isset($scores[$topic])) {
            $scores[$topic] = 0;
        }

        /*
         * 現在の発言を最重要視
         */
        $scores[$topic] += $score * 5;
    }


    /*
     * --------------------------------------------------------
     * 2. 直近の会話
     * --------------------------------------------------------
     */

    $recent = getRecentConversation($history, 8);

    /*
     * 古い発言ほど影響を弱くする。
     */
    $distance = 0;

    for ($i = count($recent) - 1; $i >= 0; $i--) {

        if (!isset($recent[$i]['content'])) {
            continue;
        }

        $content = (string)$recent[$i]['content'];

        $topics = detectTopics($content);

        /*
         * 直近ほど高い重み
         */
        $weight = max(
            1,
            4 - $distance
        );

        foreach ($topics as $topic => $score) {

            if (!isset($scores[$topic])) {
                $scores[$topic] = 0;
            }

            $scores[$topic] += $score * $weight;
        }

        $distance++;
    }


    /*
     * --------------------------------------------------------
     * 3. 前回の話題
     * --------------------------------------------------------
     *
     * 「それ」「その方法」などの場合、
     * 前回の話題を強く残す。
     */
    if (
        isset($previousContext['topic']) &&
        $previousContext['topic'] !== ''
    ) {

        $topic = (string)$previousContext['topic'];

        if (!isset($scores[$topic])) {
            $scores[$topic] = 0;
        }

        $scores[$topic] += 4;
    }


    /*
     * --------------------------------------------------------
     * 4. 結果
     * --------------------------------------------------------
     */

    arsort($scores);

    if (empty($scores)) {

        /*
         * 話題が判定できない場合は、
         * 前回の話題を維持する。
         */
        if (
            isset($previousContext['topic']) &&
            $previousContext['topic'] !== ''
        ) {

            return [
                'topic' => (string)$previousContext['topic'],
                'score' => 1,
                'candidates' => []
            ];
        }

        return [
            'topic' => '',
            'score' => 0,
            'candidates' => []
        ];
    }


    $topic = (string)array_key_first($scores);

    return [
        'topic' => $topic,
        'score' => (int)$scores[$topic],
        'candidates' => $scores
    ];
}


/* ============================================================
 * 指示語・継続質問の解析
 * ============================================================ */


/**
 * 文脈参照をしている可能性が高いか
 */
function hasContextReference(string $text): bool
{
    $references = [
        'それ',
        'これ',
        'あれ',
        'その',
        'この',
        'あの',
        'さっき',
        '先ほど',
        '前の',
        '前述',
        '上記',
        '今の',
        '今話している',
        'その話',
        'この話',
        'その方法',
        'この方法',
        '同じもの',
        '同じ方法'
    ];

    return containsAny($text, $references);
}


/**
 * 詳細化要求か
 */
function isExpansionRequest(string $text): bool
{
    $keywords = [
        '詳しく',
        'もっと詳しく',
        '詳細',
        '具体的に',
        'もう少し',
        '掘り下げ',
        '深く',
        '詳しく教えて',
        '具体例',
        '例を',
        '説明して',
        '解説して'
    ];

    return containsAny($text, $keywords);
}


/**
 * 確認・肯定応答か
 */
function isAffirmative(string $text): bool
{
    $keywords = [
        'はい',
        'うん',
        'そう',
        'そうです',
        'お願いします',
        'OK',
        'ＯＫ',
        '了解',
        'わかった',
        '分かった',
        'その通り'
    ];

    return containsAny($text, $keywords);
}


/**
 * 否定・変更か
 */
function isNegativeOrChange(string $text): bool
{
    $keywords = [
        '違う',
        'ちがう',
        'いや',
        'ではなく',
        '変更',
        'やめて',
        '別の',
        '別に',
        '違います'
    ];

    return containsAny($text, $keywords);
}


/* ============================================================
 * 意図解析
 * ============================================================ */


/**
 * ユーザーの意図を推定
 *
 * 「回答そのもの」を決めるのではなく、
 * 現在何をしようとしているのかを推定する。
 */
function detectIntent(
    string $currentMessage,
    array $context
): array {

    $reference = hasContextReference($currentMessage);
    $expansion = isExpansionRequest($currentMessage);
    $affirmative = isAffirmative($currentMessage);
    $negative = isNegativeOrChange($currentMessage);

    if ($negative) {
        return [
            'name' => 'change_topic',
            'label' => '話題変更',
            'context_reference' => $reference
        ];
    }

    if ($expansion) {
        return [
            'name' => 'expand',
            'label' => '詳細説明要求',
            'context_reference' => $reference
        ];
    }

    if ($affirmative) {
        return [
            'name' => 'affirm',
            'label' => '肯定・継続',
            'context_reference' => $reference
        ];
    }

    if ($reference) {
        return [
            'name' => 'context_question',
            'label' => '文脈参照質問',
            'context_reference' => true
        ];
    }

    if (containsAny($currentMessage, [
        'こんにちは',
        'こんばんは',
        'おはよう',
        'hello',
        'Hello',
        'hi',
        'Hi'
    ])) {
        return [
            'name' => 'greeting',
            'label' => 'あいさつ',
            'context_reference' => false
        ];
    }

    if (containsAny($currentMessage, [
        '作りたい',
        '作る',
        '作り方',
        '方法',
        'やり方',
        '実装',
        'コード',
        '書いて'
    ])) {
        return [
            'name' => 'how_to',
            'label' => '方法・実装',
            'context_reference' => false
        ];
    }

    if (containsAny($currentMessage, [
        'なぜ',
        'どうして',
        '理由'
    ])) {
        return [
            'name' => 'why',
            'label' => '理由質問',
            'context_reference' => false
        ];
    }

    if (containsAny($currentMessage, [
        '何',
        'とは',
        '教えて',
        'できますか',
        'できる？',
        'できます？',
        '?',
        '？'
    ])) {
        return [
            'name' => 'question',
            'label' => '質問',
            'context_reference' => false
        ];
    }

    return [
        'name' => 'conversation',
        'label' => '通常会話',
        'context_reference' => false
    ];
}


/* ============================================================
 * 文脈対象の推定
 * ============================================================ */


/**
 * 「それ」「その方法」などが何を指すか推定する。
 *
 * 現段階ではLLMではないため、
 * 直近のユーザー発言・AI回答・話題を組み合わせて
 * 「参照対象候補」を作る。
 */
function resolveContextReference(
    string $currentMessage,
    array $history,
    array $context
): array {

    if (!hasContextReference($currentMessage)) {
        return [
            'resolved' => false,
            'subject' => '',
            'source' => ''
        ];
    }


    /*
     * 直前のユーザー発言
     */
    $lastUser = getLastUserMessage($history);


    /*
     * 直前のAI回答
     */
    $lastAi = getLastAiMessage($history);


    /*
     * 「その方法」「この方法」など
     *
     * 直前のユーザー発言が質問・依頼なら、
     * その内容を参照対象にする。
     */
    if (
        containsAny($currentMessage, [
            'その方法',
            'この方法',
            'そのやり方',
            'このやり方'
        ])
    ) {

        if ($lastUser !== '') {
            return [
                'resolved' => true,
                'subject' => shortenText($lastUser, 120),
                'source' => '直前のユーザー発言'
            ];
        }

        if ($lastAi !== '') {
            return [
                'resolved' => true,
                'subject' => shortenText($lastAi, 120),
                'source' => '直前のAI回答'
            ];
        }
    }


    /*
     * 「その話」「この話」
     */
    if (
        containsAny($currentMessage, [
            'その話',
            'この話',
            'さっきの話',
            '先ほどの話'
        ])
    ) {

        if (
            isset($context['topic']) &&
            $context['topic'] !== ''
        ) {

            return [
                'resolved' => true,
                'subject' => (string)$context['topic'],
                'source' => '現在の会話トピック'
            ];
        }
    }


    /*
     * 一般的な「それ」「これ」
     *
     * まず直前のAI回答を参照する。
     */
    if ($lastAi !== '') {

        return [
            'resolved' => true,
            'subject' => shortenText($lastAi, 120),
            'source' => '直前のAI回答'
        ];
    }


    /*
     * AI回答がない場合はユーザー発言
     */
    if ($lastUser !== '') {

        return [
            'resolved' => true,
            'subject' => shortenText($lastUser, 120),
            'source' => '直前のユーザー発言'
        ];
    }


    /*
     * 最後の保険
     */
    if (
        isset($context['topic']) &&
        $context['topic'] !== ''
    ) {

        return [
            'resolved' => true,
            'subject' => (string)$context['topic'],
            'source' => '現在の会話トピック'
        ];
    }


    return [
        'resolved' => false,
        'subject' => '',
        'source' => ''
    ];
}


/* ============================================================
 * 回答生成
 * ============================================================ */


/**
 * 文脈を説明するための前置き
 */
function contextPrefix(
    array $context,
    array $reference
): string {

    $topic = isset($context['topic'])
        ? (string)$context['topic']
        : '';

    if (
        $reference['resolved'] &&
        $reference['subject'] !== ''
    ) {

        return
            "先ほどの会話を踏まえると、" .
            "「" . $reference['subject'] . "」についての話ですね。\n\n";
    }

    if ($topic !== '') {

        return
            "現在は「" . $topic . "」について話していますね。\n\n";
    }

    return '';
}


/**
 * 文脈を利用して回答する。
 */
function generateContextAwareResponse(
    string $message,
    array $history,
    array $context,
    array $intent,
    array $reference
): string {

    $intentName = (string)$intent['name'];

    $topic = isset($context['topic'])
        ? (string)$context['topic']
        : '';


    /* --------------------------------------------------------
     * あいさつ
     * -------------------------------------------------------- */

    if ($intentName === 'greeting') {

        return
            "こんにちは！\n\n" .
            "私はApache＋PHPだけで動作している自作AIの第2段階です。\n" .
            "今は会話履歴から文脈を推定できるようになっています。\n\n" .
            "例えば「それを詳しく教えて」のような、" .
            "前の発言を参照する質問にも対応していきます。";
    }


    /* --------------------------------------------------------
     * 話題変更
     * -------------------------------------------------------- */

    if ($intentName === 'change_topic') {

        return
            "わかりました。\n\n" .
            "ここまでの話題から切り替えましょう。\n" .
            "新しいテーマを教えてください。";
    }


    /* --------------------------------------------------------
     * 文脈参照
     * -------------------------------------------------------- */

    if ($intentName === 'context_question') {

        if ($reference['resolved']) {

            $subject = $reference['subject'];

            if ($topic !== '') {

                return
                    "はい。先ほどの話を引き継いで回答します。\n\n" .
                    "今回の文脈では、" .
                    "「" . $subject . "」を指していると判断しました。\n\n" .
                    "現在の話題は「" . $topic . "」です。\n\n" .
                    "まだ本物のLLMではないため高度な意味理解ではありませんが、" .
                    "過去の会話履歴を参照して、現在の発言との関係を判断しています。";
            }

            return
                "先ほどの会話を参照していると判断しました。\n\n" .
                "参照対象：\n" .
                $subject . "\n\n" .
                "このように、現在の発言だけではなく、" .
                "過去の会話履歴を利用しています。";
        }

        return
            "「それ」が何を指しているのか、" .
            "現在の会話履歴からは十分に判断できませんでした。\n\n" .
            "もう少し具体的に指定してもらえると回答できます。";
    }


    /* --------------------------------------------------------
     * 詳細説明
     * -------------------------------------------------------- */

    if ($intentName === 'expand') {

        $prefix = contextPrefix(
            $context,
            $reference
        );

        if ($topic === 'PHP') {

            return
                $prefix .
                "PHPについて詳しく説明します。\n\n" .
                "今回の自作AIでは、PHPをAIそのものとして扱うのではなく、" .
                "入力を受け取り、会話履歴を管理し、文脈を解析し、" .
                "回答を生成するための制御役として使っています。\n\n" .
                "次の段階では、この文脈処理に記憶機能を追加できます。";
        }

        if ($topic === 'AI' || $topic === '自作AI') {

            return
                $prefix .
                "自作AIの構造を詳しくすると、次のようになります。\n\n" .
                "1. ユーザー入力を受け取る\n" .
                "2. 過去の会話を取得する\n" .
                "3. 現在の話題を推定する\n" .
                "4. ユーザーの意図を推定する\n" .
                "5. 「それ」「これ」などの参照先を解決する\n" .
                "6. 記憶や知識を検索する\n" .
                "7. 回答を生成する\n\n" .
                "現在はこのうち、会話履歴・話題・意図・参照先の推定までを実装しています。";
        }

        if ($topic === '文脈') {

            return
                $prefix .
                "文脈理解では、現在の入力だけを見ません。\n\n" .
                "直近のユーザー発言、AIの回答、過去の話題、" .
                "現在の質問が追加質問なのか話題変更なのか、といった情報を組み合わせます。\n\n" .
                "例えば「それを詳しく」という短い入力でも、" .
                "直前の会話を参照して意味を補完します。";
        }

        return
            $prefix .
            "直前までの会話を踏まえて、もう少し詳しく説明します。\n\n" .
            "現在のモックAIでは、会話履歴・話題・質問形式を組み合わせて、" .
            "回答の方向を決めています。\n\n" .
            "さらに高度化するには、次に「記憶」と「知識検索」を追加するのが効果的です。";
    }


    /* --------------------------------------------------------
     * 肯定・継続
     * -------------------------------------------------------- */

    if ($intentName === 'affirm') {

        $prefix = contextPrefix(
            $context,
            $reference
        );

        if ($prefix === '') {

            $prefix =
                "はい。続きを説明します。\n\n";
        }

        return
            $prefix .
            "このまま現在の話題を引き継ぎます。\n\n" .
            "現在の私は会話履歴を短期記憶として利用しているため、" .
            "直前の話題を維持したまま次の回答を生成できます。\n\n" .
            "次の段階では、この短期記憶から重要な情報だけを抽出する仕組みを追加できます。";
    }


    /* --------------------------------------------------------
     * 方法・実装
     * -------------------------------------------------------- */

    if ($intentName === 'how_to') {

        if ($topic === '自作AI' || $topic === 'AI') {

            return
                "自作AIを発展させるなら、まず文脈処理を安定させるのがよいです。\n\n" .
                "現在のコードでは、\n" .
                "・会話履歴\n" .
                "・現在の話題\n" .
                "・ユーザーの意図\n" .
                "・指示語の参照先\n" .
                "を分けて扱っています。\n\n" .
                "次は「重要な情報だけを記憶する長期記憶」を追加できます。";
        }

        if ($topic === 'PHP') {

            return
                "PHPで実装する場合は、まず会話履歴を配列として保持し、" .
                "その履歴を文脈解析関数へ渡します。\n\n" .
                "今回のコードでは、" .
                "detectContextTopic()、detectIntent()、" .
                "resolveContextReference() を分けています。\n\n" .
                "この分離によって、後からAIモデルを接続しやすくなります。";
        }

        return
            "方法を考えるには、まず現在の会話文脈を維持します。\n\n" .
            "現在の話題：" . ($topic !== '' ? $topic : '未確定') . "\n\n" .
            "その上で、具体的な処理を追加していく構造にしています。";
    }


    /* --------------------------------------------------------
     * 理由
     * -------------------------------------------------------- */

    if ($intentName === 'why') {

        $prefix = contextPrefix(
            $context,
            $reference
        );

        return
            $prefix .
            "理由を説明します。\n\n" .
            "現在の自作AIでは、単純なキーワード判定だけに頼らず、" .
            "会話履歴を使って現在の発言の意味を補完する必要があります。\n\n" .
            "人間同士の会話では、毎回すべてを言い直さなくても話が通じます。" .
            "その仕組みに近づけるため、会話履歴を文脈として利用しています。";
    }


    /* --------------------------------------------------------
     * 通常質問
     * -------------------------------------------------------- */

    if ($intentName === 'question') {

        $prefix = contextPrefix(
            $context,
            $reference
        );

        if ($topic !== '') {

            return
                $prefix .
                "現在の話題は「" . $topic . "」だと推定しています。\n\n" .
                "質問を受け取りました。\n\n" .
                "ただし、現在のAIはまだ外部の大規模言語モデルには接続していません。" .
                "そのため、持っているモック知識の範囲で回答しています。\n\n" .
                "今後はここに知識検索と推論処理を追加できます。";
        }

        return
            "質問を受け取りました。\n\n" .
            "まだ十分な文脈を取得できていないため、" .
            "現在の質問だけでは詳しく判断できません。\n\n" .
            "会話を続けてもらえれば、その履歴を使って文脈を推定します。";
    }


    /* --------------------------------------------------------
     * 通常会話
     * -------------------------------------------------------- */

    $prefix = contextPrefix(
        $context,
        $reference
    );

    if ($prefix !== '') {

        return
            $prefix .
            "会話を続けましょう。\n\n" .
            "現在の話題を「" . $topic . "」として保持しています。\n" .
            "前の発言を踏まえて、この話題を継続できます。";
    }


    return
        "入力を受け取りました。\n\n" .
        "まだ明確な話題を特定できませんでした。\n" .
        "会話が続けば、履歴から話題と文脈を推定できるようになります。";
}


/* ============================================================
 * メインAI処理
 * ============================================================ */


/**
 * AI処理全体
 *
 * ここが現在の「AIパイプライン」。
 *
 * 入力
 * ↓
 * 履歴
 * ↓
 * 文脈
 * ↓
 * 意図
 * ↓
 * 参照解決
 * ↓
 * 回答
 */
function runAI(
    string $userMessage,
    array $history,
    array $previousContext
): array {

    /*
     * 1. 入力正規化
     */
    $normalized = normalizeText(
        $userMessage
    );


    /*
     * 2. 文脈解析
     */
    $contextResult = detectContextTopic(
        $normalized,
        $history,
        $previousContext
    );


    /*
     * 現在のAIコンテキスト
     */
    $context = [
        'topic' => $contextResult['topic'],
        'topic_score' => $contextResult['score'],
        'last_subject' => '',
        'last_intent' => '',
        'turn' => (
            isset($previousContext['turn'])
                ? (int)$previousContext['turn']
                : 0
        ) + 1
    ];


    /*
     * 3. 意図解析
     */
    $intent = detectIntent(
        $normalized,
        $context
    );


    /*
     * 4. 文脈参照解決
     */
    $reference = resolveContextReference(
        $normalized,
        $history,
        $context
    );


    /*
     * 5. 参照対象をコンテキストへ保存
     */
    if ($reference['resolved']) {

        $context['last_subject'] =
            (string)$reference['subject'];
    }


    /*
     * 6. 意図を保存
     */
    $context['last_intent'] =
        (string)$intent['name'];


    /*
     * 7. 回答生成
     */
    $response = generateContextAwareResponse(
        $normalized,
        $history,
        $context,
        $intent,
        $reference
    );


    /*
     * 8. AI状態を返す
     */
    return [
        'response' => $response,
        'context' => $context,
        'intent' => $intent,
        'reference' => $reference
    ];
}


/* ============================================================
 * POST処理
 * ============================================================ */

$error = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    /* --------------------------------------------------------
     * リセット
     * -------------------------------------------------------- */

    if (isset($_POST['reset'])) {

        $_SESSION['ai_messages'] = [];

        $_SESSION['ai_context'] = [
            'topic' => '',
            'topic_score' => 0,
            'last_subject' => '',
            'last_intent' => '',
            'turn' => 0
        ];

        header(
            'Location: ' .
            $_SERVER['PHP_SELF']
        );

        exit;
    }


    /* --------------------------------------------------------
     * メッセージ
     * -------------------------------------------------------- */

    if (isset($_POST['message'])) {

        $userMessage = trim(
            (string)$_POST['message']
        );


        if ($userMessage === '') {

            $error =
                'メッセージを入力してください。';

        } else {

            /*
             * 現在のAI状態を取得
             */
            $previousContext =
                $_SESSION['ai_context'];


            /*
             * 現在までの履歴を取得
             *
             * ユーザー発言を追加する前に取得する。
             *
             * これにより「現在の入力」と
             * 「過去の会話」を明確に分離できる。
             */
            $history =
                $_SESSION['ai_messages'];


            /*
             * AIへ渡す
             */
            $result = runAI(
                $userMessage,
                $history,
                $previousContext
            );


            /*
             * ユーザー発言を保存
             */
            addMessage(
                'user',
                $userMessage
            );


            /*
             * AI回答を保存
             */
            addMessage(
                'ai',
                $result['response']
            );


            /*
             * AIの文脈状態を保存
             */
            $_SESSION['ai_context'] =
                $result['context'];
        }
    }
}


/* ============================================================
 * 表示用データ
 * ============================================================ */

$messages =
    $_SESSION['ai_messages'];

$aiContext =
    $_SESSION['ai_context'];


/* ============================================================
 * HTML
 * ============================================================ */
?>
<!DOCTYPE html>
<html lang="ja">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>自作AI - Context Engine</title>


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
    max-width: 1000px;

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

    min-height: 70px;

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
    width: 44px;
    height: 44px;

    border-radius: 13px;

    display: flex;
    align-items: center;
    justify-content: center;

    background:
        linear-gradient(
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
    margin-top: 3px;

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
    max-width: 680px;

    margin: 55px auto;

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

    background:
        linear-gradient(
            135deg,
            #2563eb,
            #7c3aed
        );

    color: #ffffff;

    font-size: 25px;
    font-weight: bold;
}

.welcome h1 {
    margin: 0 0 12px;

    font-size: 27px;
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

    max-width: 84%;
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

    margin: 2px 9px 0;

    border-radius: 50%;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 10px;
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
   文脈情報
   ============================================================ */

.context-panel {
    max-width: 680px;

    margin: 0 auto 25px;

    padding: 12px 14px;

    border: 1px solid #e5e7eb;

    border-radius: 10px;

    background: #f8fafc;

    color: #64748b;

    font-size: 11px;
}

.context-title {
    margin-bottom: 6px;

    color: #334155;

    font-weight: bold;
}

.context-row {
    display: flex;

    gap: 10px;

    margin: 3px 0;
}

.context-label {
    width: 90px;

    flex: 0 0 90px;

    color: #94a3b8;
}

.context-value {
    color: #475569;
}


/* ============================================================
   エラー
   ============================================================ */

.error {
    max-width: 680px;

    margin: 0 auto 15px;

    padding: 10px 14px;

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
        0 0 0 3px rgba(
            37,
            99,
            235,
            .10
        );
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

.send-button:disabled {
    background: #94a3b8;

    cursor: wait;
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
        max-width: 94%;
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

    .context-panel {
        font-size: 10px;
    }

    .context-label {
        width: 75px;
        flex-basis: 75px;
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

                    Context Engine /
                    PHP Mock AI /
                    第2段階

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
                    自作AI
                </h1>

                <p>
                    第2段階：Context Engine
                </p>

                <p>
                    会話履歴から文脈を推定します。
                </p>

                <p>
                    例えば「それを詳しく教えて」と入力してみてください。
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

            $role =
                isset($message['role'])
                    ? (string)$message['role']
                    : 'ai';

            $content =
                isset($message['content'])
                    ? (string)$message['content']
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


        <?php if (!empty($messages)): ?>

            <!-- ==================================================
                 現在のAI文脈
                 ================================================== -->

            <div class="context-panel">

                <div class="context-title">
                    AIが現在保持している文脈
                </div>

                <div class="context-row">

                    <div class="context-label">
                        話題
                    </div>

                    <div class="context-value">

                        <?= e(
                            $aiContext['topic'] !== ''
                                ? $aiContext['topic']
                                : '未確定'
                        ) ?>

                    </div>

                </div>


                <div class="context-row">

                    <div class="context-label">
                        ターン
                    </div>

                    <div class="context-value">

                        <?= e(
                            (string)$aiContext['turn']
                        ) ?>

                    </div>

                </div>


                <div class="context-row">

                    <div class="context-label">
                        最後の意図
                    </div>

                    <div class="context-value">

                        <?= e(
                            $aiContext['last_intent'] !== ''
                                ? $aiContext['last_intent']
                                : '未確定'
                        ) ?>

                    </div>

                </div>


                <?php if (
                    isset($aiContext['last_subject']) &&
                    $aiContext['last_subject'] !== ''
                ): ?>

                    <div class="context-row">

                        <div class="context-label">
                            参照対象
                        </div>

                        <div class="context-value">

                            <?= e(
                                shortenText(
                                    (string)$aiContext['last_subject'],
                                    100
                                )
                            ) ?>

                        </div>

                    </div>

                <?php endif; ?>


            </div>

        <?php endif; ?>


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
 * チャットを最下部へ
 * ============================================================
 */

(function () {

    const chat =
        document.getElementById('chat');

    if (chat) {
        chat.scrollTop =
            chat.scrollHeight;
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

    textarea.addEventListener(
        'keydown',
        function (event) {

            if (
                (event.ctrlKey || event.metaKey) &&
                event.key === 'Enter'
            ) {

                event.preventDefault();

                if (this.form) {
                    this.form.submit();
                }
            }

        }
    );

})();


/*
 * ============================================================
 * 二重送信防止
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

    form.addEventListener(
        'submit',
        function () {

            button.disabled = true;

            button.textContent =
                '処理中...';

        }
    );

})();

</script>


</body>

</html>

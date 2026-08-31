<?php
session_start();

/*
 * ============================================================
 * My AI - Stage 2
 * 会話履歴を実際に利用する文脈エンジン
 *
 * Apache + PHP
 * データベース不要
 * 1ファイル構成
 *
 * この段階では「学習」は行わない。
 *
 * 実装するもの：
 *   - 会話履歴
 *   - 文脈解析
 *   - 話題推定
 *   - 意図推定
 *   - 指示語の簡易解決
 *   - 文脈を利用したモック回答
 *
 * 将来的に：
 *   - 記憶
 *   - 知識検索
 *   - LLM / API
 *   - 本格的な推論
 * に発展させる。
 * ============================================================
 */


/*
 * ============================================================
 * 設定
 * ============================================================
 */

const MAX_HISTORY = 30;
const CONTEXT_TURNS = 8;

/*
 * 開発中は true。
 *
 * AIが何を参照しているのか確認できるようにする。
 * 本番運用時には false にできる。
 */
const DEBUG_MODE = true;


/*
 * ============================================================
 * セッション初期化
 * ============================================================
 */

if (!isset($_SESSION['messages']) || !is_array($_SESSION['messages'])) {
    $_SESSION['messages'] = [];
}

if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
    $_SESSION['context'] = [
        'topic' => '',
        'topic_keywords' => [],
        'last_user_message' => '',
        'last_ai_message' => '',
        'last_reference' => '',
        'turn_count' => 0
    ];
}


/*
 * ============================================================
 * 会話リセット
 * ============================================================
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset'])) {

    $_SESSION['messages'] = [];

    $_SESSION['context'] = [
        'topic' => '',
        'topic_keywords' => [],
        'last_user_message' => '',
        'last_ai_message' => '',
        'last_reference' => '',
        'turn_count' => 0
    ];

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}


/*
 * ============================================================
 * メッセージ処理
 * ============================================================
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {

    $userMessage = trim((string)$_POST['message']);

    if ($userMessage !== '') {

        /*
         * ----------------------------------------------------
         * 重要：
         *
         * 回答を作る前に、現在までの履歴を取得する。
         *
         * 以前のコード：
         *
         * generateMockResponse($userMessage)
         *
         * ではなく、
         *
         * generateContextualResponse(
         *     $userMessage,
         *     $_SESSION['messages'],
         *     $_SESSION['context']
         * )
         *
         * とする。
         * ----------------------------------------------------
         */

        $historyBeforeMessage = $_SESSION['messages'];

        /*
         * 現在の文脈を分析
         */
        $analysis = analyzeContext(
            $userMessage,
            $historyBeforeMessage,
            $_SESSION['context']
        );


        /*
         * 文脈を利用して回答生成
         */
        $aiMessage = generateContextualResponse(
            $userMessage,
            $historyBeforeMessage,
            $analysis
        );


        /*
         * ----------------------------------------------------
         * ユーザー発言を保存
         * ----------------------------------------------------
         */

        $_SESSION['messages'][] = [
            'role' => 'user',
            'content' => $userMessage,
            'time' => date('Y-m-d H:i:s')
        ];


        /*
         * ----------------------------------------------------
         * AI回答を保存
         * ----------------------------------------------------
         */

        $_SESSION['messages'][] = [
            'role' => 'ai',
            'content' => $aiMessage,
            'time' => date('Y-m-d H:i:s')
        ];


        /*
         * ----------------------------------------------------
         * 文脈状態を更新
         * ----------------------------------------------------
         */

        $_SESSION['context'] = updateContext(
            $_SESSION['context'],
            $userMessage,
            $aiMessage,
            $analysis
        );


        /*
         * 履歴が増えすぎないように制限
         */
        if (count($_SESSION['messages']) > MAX_HISTORY) {

            $_SESSION['messages'] = array_slice(
                $_SESSION['messages'],
                -MAX_HISTORY
            );
        }
    }


    /*
     * POST再送信防止
     */
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}


/*
 * ============================================================
 * 文脈解析
 * ============================================================
 */

/**
 * 現在の入力と過去の会話から文脈を分析する。
 *
 * この関数が第2段階の中心。
 *
 * まだLLMは使わない。
 * PHPのルールで「文脈らしきもの」を構築する。
 */
function analyzeContext($message, $history, $previousContext)
{
    $message = normalizeText($message);

    /*
     * 直近の会話だけを取り出す。
     */
    $recentMessages = getRecentMessages(
        $history,
        CONTEXT_TURNS
    );


    /*
     * 現在の話題を推定
     */
    $topic = detectTopic(
        $message,
        $recentMessages,
        $previousContext
    );


    /*
     * 意図を推定
     */
    $intent = detectIntent($message);


    /*
     * 指示語・省略表現などを確認
     */
    $reference = resolveReference(
        $message,
        $recentMessages,
        $previousContext
    );


    /*
     * 過去の発言から関連するものを抽出
     */
    $relatedMessages = findRelatedMessages(
        $message,
        $recentMessages,
        $topic
    );


    /*
     * 文脈が存在するか
     */
    $hasContext = (
        !empty($recentMessages) &&
        (
            $reference !== '' ||
            $topic !== '' ||
            !empty($relatedMessages)
        )
    );


    return [
        'normalized_message' => $message,
        'topic' => $topic,
        'intent' => $intent,
        'reference' => $reference,
        'related_messages' => $relatedMessages,
        'recent_messages' => $recentMessages,
        'has_context' => $hasContext
    ];
}


/*
 * ============================================================
 * 入力正規化
 * ============================================================
 */

function normalizeText($text)
{
    $text = trim($text);

    /*
     * 全角スペースを半角スペースへ
     */
    $text = str_replace(
        ["　", "\r\n", "\r"],
        [" ", "\n", "\n"],
        $text
    );

    return $text;
}


/*
 * ============================================================
 * 直近履歴取得
 * ============================================================
 */

function getRecentMessages($history, $limit)
{
    if (!is_array($history)) {
        return [];
    }

    return array_slice($history, -$limit);
}


/*
 * ============================================================
 * 話題推定
 * ============================================================
 */

function detectTopic($message, $recentMessages, $previousContext)
{
    /*
     * 明確な話題キーワード。
     *
     * ここでは「回答を決める」のではなく、
     * 会話の話題を推定するためだけに使用する。
     */

    $topicRules = [

        'PHP' => [
            'PHP',
            'php',
            'プログラム',
            'プログラミング',
            'コード',
            '関数',
            '変数',
            'Apache',
            'apache'
        ],

        'AI' => [
            'AI',
            'ai',
            '人工知能',
            '生成AI',
            'LLM',
            '機械学習',
            'モデル'
        ],

        '文脈理解' => [
            '文脈',
            '会話履歴',
            '履歴',
            '前の話',
            '前の質問',
            'それ',
            'これ',
            'その方法',
            '続き'
        ],

        '学習' => [
            '学習',
            '覚える',
            '覚え',
            '記憶',
            '成長',
            '賢く'
        ],

        '質問' => [
            '質問',
            '教えて',
            'どうして',
            'なぜ',
            '理由',
            '方法'
        ]
    ];


    /*
     * 現在の発言から話題を検索
     */
    foreach ($topicRules as $topic => $keywords) {

        foreach ($keywords as $keyword) {

            if (mb_stripos($message, $keyword) !== false) {
                return $topic;
            }
        }
    }


    /*
     * 現在の発言だけでは分からない場合、
     * 直近の会話から話題を引き継ぐ。
     */

    if (!empty($recentMessages)) {

        $recentText = '';

        foreach ($recentMessages as $item) {

            if (!isset($item['content'])) {
                continue;
            }

            $recentText .= "\n" . $item['content'];
        }


        foreach ($topicRules as $topic => $keywords) {

            foreach ($keywords as $keyword) {

                if (mb_stripos($recentText, $keyword) !== false) {
                    return $topic;
                }
            }
        }
    }


    /*
     * 前回の文脈があれば引き継ぐ
     */
    if (
        is_array($previousContext) &&
        !empty($previousContext['topic'])
    ) {
        return $previousContext['topic'];
    }


    return '';
}


/*
 * ============================================================
 * 意図推定
 * ============================================================
 */

function detectIntent($message)
{
    /*
     * あいさつ
     */
    if (preg_match(
        '/^(こんにちは|こんばんは|おはよう|やあ|hello|hi)[！!。.\s]*$/iu',
        $message
    )) {
        return 'greeting';
    }


    /*
     * 自己紹介
     */
    if (preg_match(
        '/あなた.*(誰|何)|自己紹介|どんなAI/iu',
        $message
    )) {
        return 'self_introduction';
    }


    /*
     * 「それ」「これ」などの継続発言
     */
    if (preg_match(
        '/^(それ|これ|その|あれ|その方法|それを|これを|続き|詳しく)[\s\S]*/u',
        $message
    )) {
        return 'context_followup';
    }


    /*
     * 理由・説明
     */
    if (preg_match(
        '/(なぜ|どうして|理由|どういう意味|とは|教えて|詳しく|説明)/u',
        $message
    )) {
        return 'explanation';
    }


    /*
     * 方法・手順
     */
    if (preg_match(
        '/(どうやって|どうすれば|方法|手順|作り方|やり方|実装)/u',
        $message
    )) {
        return 'how_to';
    }


    /*
     * 質問
     */
    if (preg_match(
        '/(\?|？|ですか|できますか|でしょうか|何|どれ|いつ|どこ)/u',
        $message
    )) {
        return 'question';
    }


    /*
     * 同意・確認
     */
    if (preg_match(
        '/^(はい|うん|そう|そうです|お願いします|OK|ok|了解|わかりました)[！!。.\s]*$/iu',
        $message
    )) {
        return 'confirmation';
    }


    /*
     * 否定
     */
    if (preg_match(
        '/^(いいえ|違う|違います|いや)[！!。.\s]*$/u',
        $message
    )) {
        return 'negative';
    }


    return 'statement';
}


/*
 * ============================================================
 * 指示語・省略された対象の解決
 * ============================================================
 */

function resolveReference($message, $recentMessages, $previousContext)
{
    /*
     * 文脈参照を必要としそうな表現
     */
    $referenceWords = [
        'それ',
        'これ',
        'あれ',
        'その',
        'この',
        'あの',
        'それを',
        'これを',
        'その方法',
        'その話',
        '続き'
    ];


    $containsReference = false;

    foreach ($referenceWords as $word) {

        if (mb_stripos($message, $word) !== false) {
            $containsReference = true;
            break;
        }
    }


    /*
     * 指示語がなければ、
     * 明示的な参照はない。
     */
    if (!$containsReference) {
        return '';
    }


    /*
     * 直近のユーザー発言を探す。
     */
    for ($i = count($recentMessages) - 1; $i >= 0; $i--) {

        $item = $recentMessages[$i];

        if (
            isset($item['role']) &&
            $item['role'] === 'user' &&
            isset($item['content'])
        ) {

            return $item['content'];
        }
    }


    /*
     * 直近のユーザー発言がない場合、
     * 前回の参照情報を利用する。
     */
    if (
        is_array($previousContext) &&
        !empty($previousContext['last_reference'])
    ) {
        return $previousContext['last_reference'];
    }


    return '';
}


/*
 * ============================================================
 * 関連発言検索
 * ============================================================
 */

function findRelatedMessages($message, $recentMessages, $topic)
{
    $results = [];


    /*
     * 現在の発言から重要そうな語を抽出
     */
    $keywords = extractKeywords($message);


    foreach ($recentMessages as $item) {

        if (
            !isset($item['content']) ||
            !isset($item['role'])
        ) {
            continue;
        }

        $content = $item['content'];

        $score = 0;


        /*
         * 話題一致
         */
        if (
            $topic !== '' &&
            mb_stripos($content, $topic) !== false
        ) {
            $score += 3;
        }


        /*
         * キーワード一致
         */
        foreach ($keywords as $keyword) {

            if (
                $keyword !== '' &&
                mb_stripos($content, $keyword) !== false
            ) {
                $score++;
            }
        }


        /*
         * 指示語を含む質問なら、
         * 直近の発言を強く関連付ける。
         */
        if (
            preg_match('/(それ|これ|その|あれ|続き)/u', $message) &&
            $item['role'] === 'user'
        ) {
            $score += 2;
        }


        if ($score > 0) {

            $results[] = [
                'role' => $item['role'],
                'content' => $content,
                'score' => $score
            ];
        }
    }


    /*
     * スコアの高い順に並べる
     */
    usort(
        $results,
        function ($a, $b) {
            return $b['score'] <=> $a['score'];
        }
    );


    /*
     * 最大3件
     */
    return array_slice($results, 0, 3);
}


/*
 * ============================================================
 * キーワード抽出
 * ============================================================
 */

function extractKeywords($text)
{
    /*
     * 日本語では本格的な形態素解析をまだ行わない。
     *
     * Stage 2では、
     * 明示的な重要語を簡易抽出する。
     */

    $knownKeywords = [
        'AI',
        '人工知能',
        'PHP',
        'Apache',
        'プログラム',
        'プログラミング',
        'コード',
        '会話',
        '会話履歴',
        '文脈',
        '記憶',
        '学習',
        '知識',
        'API',
        'LLM',
        'モデル',
        'データベース',
        'DB',
        'モック',
        '質問',
        '回答'
    ];


    $found = [];


    foreach ($knownKeywords as $keyword) {

        if (mb_stripos($text, $keyword) !== false) {
            $found[] = $keyword;
        }
    }


    return array_values(array_unique($found));
}


/*
 * ============================================================
 * 文脈更新
 * ============================================================
 */

function updateContext(
    $previousContext,
    $userMessage,
    $aiMessage,
    $analysis
) {

    $context = is_array($previousContext)
        ? $previousContext
        : [];


    $context['topic'] =
        $analysis['topic'] !== ''
            ? $analysis['topic']
            : ($context['topic'] ?? '');


    $context['topic_keywords'] =
        extractKeywords($userMessage);


    $context['last_user_message'] =
        $userMessage;


    $context['last_ai_message'] =
        $aiMessage;


    /*
     * 指示語が解決できた場合、
     * その対象を保持する。
     */
    if (!empty($analysis['reference'])) {

        $context['last_reference'] =
            $analysis['reference'];

    } elseif (
        empty($context['last_reference']) &&
        $userMessage !== ''
    ) {

        /*
         * 最初の会話では現在の発言を
         * 将来の参照対象候補として保持。
         */
        $context['last_reference'] =
            $userMessage;
    }


    $context['turn_count'] =
        (int)($context['turn_count'] ?? 0) + 1;


    return $context;
}


/*
 * ============================================================
 * 文脈を利用した回答生成
 * ============================================================
 */

function generateContextualResponse(
    $message,
    $history,
    $analysis
) {

    $intent = $analysis['intent'];
    $topic = $analysis['topic'];
    $reference = $analysis['reference'];


    /*
     * --------------------------------------------------------
     * あいさつ
     * --------------------------------------------------------
     */

    if ($intent === 'greeting') {

        return
            "こんにちは！\n\n" .
            "私はApache＋PHPで作っている自作AIのモックです。\n" .
            "現在は本物のAIモデルによる学習はしていません。\n\n" .
            "この段階では、会話履歴を使って文脈を考える仕組みを実験しています。";
    }


    /*
     * --------------------------------------------------------
     * 自己紹介
     * --------------------------------------------------------
     */

    if ($intent === 'self_introduction') {

        return
            "私はApache＋PHPだけで動かしている自作AIのプロトタイプです。\n\n" .
            "現在はデータベースも外部AI APIも使用していません。\n\n" .
            "第2段階として、過去の会話履歴を参照し、" .
            "現在の発言との関係を推定する文脈エンジンを実装しています。\n\n" .
            "まだ機械学習による「学習」はしていません。";
    }


    /*
     * --------------------------------------------------------
     * 「はい」「OK」など
     *
     * ここが重要。
     *
     * 現在の発言だけを見ると意味が曖昧。
     * 直前のAI発言を参照して回答する。
     * --------------------------------------------------------
     */

    if ($intent === 'confirmation') {

        $lastAi = findLastMessageByRole(
            $history,
            'ai'
        );


        if ($lastAi !== '') {

            return
                "了解しました。\n\n" .
                "直前の会話を引き継いで進めます。\n\n" .
                "直前の私の回答は、\n" .
                "「" . truncateText($lastAi, 120) . "」\n\n" .
                "でした。\n\n" .
                "この内容を現在の文脈として扱います。";
        }


        return
            "了解しました。\n\n" .
            "まだ参照できる過去の会話はありません。";
    }


    /*
     * --------------------------------------------------------
     * 「違う」など
     * --------------------------------------------------------
     */

    if ($intent === 'negative') {

        $lastAi = findLastMessageByRole(
            $history,
            'ai'
        );


        if ($lastAi !== '') {

            return
                "わかりました。\n\n" .
                "直前の回答とは異なる内容だったと判断します。\n" .
                "もう少し具体的に教えてもらえれば、" .
                "現在の文脈を修正して考え直します。";
        }


        return
            "わかりました。\n" .
            "どの部分が違っていたか教えてください。";
    }


    /*
     * --------------------------------------------------------
     * 文脈を使った追加質問
     * --------------------------------------------------------
     */

    if ($intent === 'context_followup') {

        if ($reference !== '') {

            return
                "はい。直前の会話にある\n\n" .
                "「" . truncateText($reference, 180) . "」\n\n" .
                "を「それ／これ」が指しているものとして解釈しました。\n\n" .
                "このように、現在の発言だけではなく、" .
                "過去の会話を参照して回答対象を推定しています。\n\n" .
                "ただし、これはまだルールベースの文脈推定です。";
        }


        return
            "「それ」が何を指しているのか、" .
            "現在の会話履歴だけでは確実に判断できませんでした。\n\n" .
            "もう少し具体的に教えてください。";
    }


    /*
     * --------------------------------------------------------
     * 「学習」「覚える」「記憶」
     * --------------------------------------------------------
     */

    if (
        mb_stripos($message, '学習') !== false ||
        mb_stripos($message, '覚え') !== false ||
        mb_stripos($message, '記憶') !== false
    ) {

        return
            "現在の私は、まだ機械学習による学習はしていません。\n\n" .
            "今行っているのは「会話履歴を保持して、" .
            "次の回答を作るときに参照する」という処理です。\n\n" .
            "つまり、\n" .
            "「会話を保存する」＝記憶の材料を持つ\n" .
            "「文脈を参照する」＝過去の会話を回答に利用する\n" .
            "「モデルを学習する」＝AIそのものの能力を変化させる\n\n" .
            "という違いがあります。\n\n" .
            "このプロトタイプでは、まず2番目の「文脈を参照する」部分を実装しています。";
    }


    /*
     * --------------------------------------------------------
     * 文脈・会話履歴について
     * --------------------------------------------------------
     */

    if (
        mb_stripos($message, '文脈') !== false ||
        mb_stripos($message, '会話履歴') !== false
    ) {

        $count = count($history);

        return
            "現在の文脈エンジンでは、過去の会話を最大 " .
            CONTEXT_TURNS .
            " 件程度参照しています。\n\n" .
            "現在のセッションには " .
            $count .
            " 件のメッセージがあります。\n\n" .
            "現在の話題としては「" .
            ($topic !== '' ? $topic : 'まだ特定できていません') .
            "」と推定しています。\n\n" .
            "次の発言でも、今回までの会話を材料として利用します。";
    }


    /*
     * --------------------------------------------------------
     * PHP
     * --------------------------------------------------------
     */

    if ($topic === 'PHP') {

        $previous = findLastMessageByRole(
            $history,
            'user'
        );


        if ($previous !== '' && $previous !== $message) {

            return
                "PHPについての話ですね。\n\n" .
                "今回の発言だけではなく、直前の会話も確認しています。\n\n" .
                "直前のユーザー発言：\n" .
                "「" . truncateText($previous, 160) . "」\n\n" .
                "このように過去の発言を文脈として利用しています。\n\n" .
                "まだPHPコードそのものを理解するAIモデルではなく、" .
                "現在は文脈エンジンの実験段階です。";
        }


        return
            "PHPについてですね。\n\n" .
            "このAIはApache＋PHP、データベースなし、" .
            "index.php 1ファイルという条件で作っています。\n\n" .
            "現在はPHPによるルールベースの文脈処理を実験しています。";
    }


    /*
     * --------------------------------------------------------
     * AI
     * --------------------------------------------------------
     */

    if ($topic === 'AI') {

        return
            "AIについての話ですね。\n\n" .
            "このプロトタイプでは、まだ本物の生成AIモデルは使用していません。\n\n" .
            "現在実装しているのは、\n" .
            "・会話履歴の保存\n" .
            "・直近の会話の参照\n" .
            "・話題の推定\n" .
            "・意図の推定\n" .
            "・指示語の簡易的な解決\n" .
            "・文脈を利用した回答\n\n" .
            "です。\n\n" .
            "次の段階では、この仕組みに記憶や知識検索を追加できます。";
    }


    /*
     * --------------------------------------------------------
     * 説明要求
     * --------------------------------------------------------
     */

    if ($intent === 'explanation') {

        if ($topic !== '') {

            return
                "「" . $topic . "」についての説明ですね。\n\n" .
                "今回の質問だけでなく、これまでの会話から話題を推定しています。\n\n" .
                "ただし現在の私はまだ本格的な生成AIではありません。\n" .
                "PHP側の文脈ルールを使って、過去の会話との関連を判断しています。\n\n" .
                "そのため、複雑な意味理解についてはまだ限界があります。";
        }


        return
            "説明を求めている質問として認識しました。\n\n" .
            "現在の私は、会話履歴と簡易的な文脈情報を使って回答しています。\n\n" .
            "具体的な対象を指定してもらえれば、" .
            "現在の会話との関係を考慮して回答します。";
    }


    /*
     * --------------------------------------------------------
     * 方法・手順
     * --------------------------------------------------------
     */

    if ($intent === 'how_to') {

        if ($topic !== '') {

            return
                "「" . $topic . "」についての方法を聞いていますね。\n\n" .
                "現在の文脈では、これまでの会話から話題を「" .
                $topic .
                "」と推定しています。\n\n" .
                "この段階ではまだ専門知識を大量に持っているわけではないので、" .
                "今後はここへ知識検索の仕組みを追加することで、" .
                "より具体的な回答へ発展させられます。";
        }


        return
            "方法を知りたい質問として認識しました。\n\n" .
            "何についての方法なのか、もう少し具体的に教えてください。";
    }


    /*
     * --------------------------------------------------------
     * 一般質問
     * --------------------------------------------------------
     */

    if ($intent === 'question') {

        if ($topic !== '') {

            return
                "質問として受け取りました。\n\n" .
                "現在の会話から、話題は「" .
                $topic .
                "」だと推定しています。\n\n" .
                "現在のモックAIでは、ここから会話履歴を参照しながら回答を組み立てています。\n\n" .
                "ただし、まだ本格的な知識検索やLLMによる推論は実装していません。";
        }


        return
            "質問として受け取りました。\n\n" .
            "現在の会話履歴から十分な文脈を特定できませんでした。\n\n" .
            "質問の前後関係をもう少し具体的にすると、" .
            "文脈エンジンがより正確に関連付けられます。";
    }


    /*
     * --------------------------------------------------------
     * 通常の発言
     * --------------------------------------------------------
     */

    if ($topic !== '') {

        return
            "「" . $topic . "」についての発言として受け取りました。\n\n" .
            "今回の入力だけでなく、過去の会話履歴も参照して、" .
            "現在の話題を「" . $topic . "」と推定しています。\n\n" .
            "これはまだ学習ではなく、会話履歴を使った文脈処理です。";
    }


    /*
     * --------------------------------------------------------
     * 文脈を特定できなかった場合
     * --------------------------------------------------------
     */

    if (!empty($history)) {

        $lastUser = findLastMessageByRole(
            $history,
            'user'
        );


        if ($lastUser !== '' && $lastUser !== $message) {

            return
                "入力を受け取りました。\n\n" .
                "過去の会話も確認しましたが、" .
                "今回の発言との明確な関連を特定できませんでした。\n\n" .
                "直前の発言は、\n" .
                "「" . truncateText($lastUser, 150) . "」\n\n" .
                "です。\n\n" .
                "話題が変わったのか、直前の話の続きなのかを" .
                "判断できるようにすることが、今後の改善ポイントです。";
        }
    }


    return
        "入力を受け取りました。\n\n" .
        "まだ十分な文脈を取得できませんでした。\n\n" .
        "私は現在、会話履歴を利用する第2段階のモックAIです。";
}


/*
 * ============================================================
 * 指定roleの最後のメッセージを取得
 * ============================================================
 */

function findLastMessageByRole($history, $role)
{
    if (!is_array($history)) {
        return '';
    }


    for ($i = count($history) - 1; $i >= 0; $i--) {

        if (
            isset($history[$i]['role']) &&
            $history[$i]['role'] === $role &&
            isset($history[$i]['content'])
        ) {

            return (string)$history[$i]['content'];
        }
    }


    return '';
}


/*
 * ============================================================
 * 長文を短くする
 * ============================================================
 */

function truncateText($text, $length)
{
    $text = trim($text);

    if (mb_strlen($text) <= $length) {
        return $text;
    }

    return mb_substr($text, 0, $length) . '…';
}


/*
 * ============================================================
 * HTMLエスケープ
 * ============================================================
 */

function h($text)
{
    return htmlspecialchars(
        (string)$text,
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
 * ============================================================
 * デバッグ表示用
 * ============================================================
 */

$debugContext = $_SESSION['context'];

$debugHistory = getRecentMessages(
    $_SESSION['messages'],
    CONTEXT_TURNS
);

?>
<!DOCTYPE html>
<html lang="ja">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0">

<title>My AI - Context Engine</title>

<style>

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    height: 100%;
}

body {
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


/* ============================================================
   アプリ全体
   ============================================================ */

.app {
    max-width: 1000px;
    height: 100vh;
    margin: 0 auto;

    display: flex;
    flex-direction: column;

    background: #fff;
}


/* ============================================================
   ヘッダー
   ============================================================ */

.header {
    min-height: 64px;

    padding: 10px 20px;

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 15px;

    border-bottom: 1px solid #e5e7eb;

    background: #fff;
}

.logo {
    font-size: 20px;
    font-weight: bold;
}

.status {
    margin-top: 3px;

    font-size: 12px;
    color: #16a34a;
}

.reset {
    border: 1px solid #d1d5db;

    background: #fff;

    border-radius: 8px;

    padding: 8px 12px;

    cursor: pointer;

    white-space: nowrap;
}

.reset:hover {
    background: #f3f4f6;
}


/* ============================================================
   チャット
   ============================================================ */

.chat {
    flex: 1;

    overflow-y: auto;

    padding: 30px 20px;
}


/* ============================================================
   ウェルカム
   ============================================================ */

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

.bubble {
    max-width: 75%;

    padding: 14px 17px;

    border-radius: 16px;

    line-height: 1.7;

    white-space: pre-wrap;

    overflow-wrap: anywhere;
}

.user .bubble {
    background: #2563eb;

    color: #fff;

    border-bottom-right-radius: 5px;
}

.ai .bubble {
    background: #f1f5f9;

    color: #222;

    border-bottom-left-radius: 5px;
}


/* ============================================================
   AIアイコン
   ============================================================ */

.ai-icon {
    width: 34px;
    height: 34px;

    margin-right: 10px;

    border-radius: 50%;

    background: #111827;

    color: #fff;

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 12px;

    flex-shrink: 0;
}


/* ============================================================
   デバッグパネル
   ============================================================ */

.debug {
    margin: 0 20px 15px;

    border: 1px solid #dbeafe;

    border-radius: 10px;

    background: #eff6ff;

    overflow: hidden;
}

.debug summary {
    padding: 10px 13px;

    cursor: pointer;

    font-size: 13px;

    font-weight: bold;

    color: #1e40af;
}

.debug-body {
    padding: 12px;

    border-top: 1px solid #dbeafe;

    font-size: 12px;

    color: #334155;
}

.debug-row {
    margin-bottom: 10px;
}

.debug-label {
    display: block;

    margin-bottom: 3px;

    font-weight: bold;

    color: #475569;
}

.debug-value {
    padding: 8px;

    background: #fff;

    border-radius: 6px;

    white-space: pre-wrap;

    overflow-wrap: anywhere;
}

.debug-history {
    max-height: 180px;

    overflow-y: auto;
}

.debug-history-item {
    padding: 7px 0;

    border-bottom: 1px solid #e5e7eb;
}

.debug-history-item:last-child {
    border-bottom: none;
}


/* ============================================================
   入力
   ============================================================ */

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

    box-shadow:
        0 0 0 3px
        rgba(37, 99, 235, .1);
}

.send {
    width: 80px;

    border: none;

    border-radius: 12px;

    background: #2563eb;

    color: #fff;

    font-size: 15px;

    cursor: pointer;
}

.send:hover {
    background: #1d4ed8;
}


/* ============================================================
   スマホ
   ============================================================ */

@media (max-width: 600px) {

    .app {
        height: 100dvh;
    }

    .header {
        padding: 10px 12px;
    }

    .chat {
        padding: 20px 12px;
    }

    .bubble {
        max-width: 88%;
    }

    .input-area {
        padding: 10px;
    }

    .debug {
        margin: 0 10px 10px;
    }

    .send {
        width: 60px;
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

        <div>

            <div class="logo">
                🤖 My AI
            </div>

            <div class="status">
                ● Online / Stage 2 Context Engine
            </div>

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


    <!-- ======================================================
         チャット
         ====================================================== -->

    <main class="chat" id="chat">


        <?php if (empty($_SESSION['messages'])): ?>


            <div class="welcome">

                <h1>
                    こんにちは 👋
                </h1>

                <p>
                    Apache＋PHPで作っている自作AIです。
                </p>

                <p>
                    現在は第2段階。
                    会話履歴を使って文脈を考えます。
                </p>

                <p>
                    まだ機械学習はしていません。
                </p>

            </div>


        <?php else: ?>


            <?php foreach ($_SESSION['messages'] as $message): ?>


                <?php
                $role = $message['role'] ?? 'ai';
                $content = $message['content'] ?? '';
                ?>


                <?php if ($role === 'user'): ?>


                    <div class="message user">

                        <div class="bubble">

                            <?= h($content) ?>

                        </div>

                    </div>


                <?php else: ?>


                    <div class="message ai">

                        <div class="ai-icon">
                            AI
                        </div>

                        <div class="bubble">

                            <?= h($content) ?>

                        </div>

                    </div>


                <?php endif; ?>


            <?php endforeach; ?>


        <?php endif; ?>


    </main>


    <!-- ======================================================
         開発用文脈表示
         ====================================================== -->

    <?php if (DEBUG_MODE): ?>


        <details class="debug">

            <summary>
                🔍 AI内部の文脈状態を確認
            </summary>


            <div class="debug-body">


                <div class="debug-row">

                    <span class="debug-label">
                        現在の話題
                    </span>

                    <div class="debug-value">

                        <?= h(
                            $debugContext['topic']
                                ?? '未特定'
                        ) ?>

                    </div>

                </div>


                <div class="debug-row">

                    <span class="debug-label">
                        話題キーワード
                    </span>

                    <div class="debug-value">

                        <?php

                        $keywords =
                            $debugContext['topic_keywords']
                            ?? [];

                        echo h(
                            empty($keywords)
                                ? 'なし'
                                : implode(
                                    ' / ',
                                    $keywords
                                )
                        );

                        ?>

                    </div>

                </div>


                <div class="debug-row">

                    <span class="debug-label">
                        ターン数
                    </span>

                    <div class="debug-value">

                        <?= h(
                            $debugContext['turn_count']
                            ?? 0
                        ) ?>

                    </div>

                </div>


                <div class="debug-row">

                    <span class="debug-label">
                        最後のユーザー発言
                    </span>

                    <div class="debug-value">

                        <?= h(
                            $debugContext['last_user_message']
                            ?? ''
                        ) ?>

                    </div>

                </div>


                <div class="debug-row">

                    <span class="debug-label">
                        AIが最後に回答した内容
                    </span>

                    <div class="debug-value">

                        <?= h(
                            $debugContext['last_ai_message']
                            ?? ''
                        ) ?>

                    </div>

                </div>


                <div class="debug-row">

                    <span class="debug-label">
                        指示語などの最後の参照対象
                    </span>

                    <div class="debug-value">

                        <?= h(
                            $debugContext['last_reference']
                            ?? ''
                        ) ?>

                    </div>

                </div>


                <div class="debug-row">

                    <span class="debug-label">
                        AIが参照可能な直近の会話
                    </span>


                    <div class="debug-value debug-history">


                        <?php if (empty($debugHistory)): ?>


                            まだ会話履歴はありません。


                        <?php else: ?>


                            <?php foreach ($debugHistory as $item): ?>


                                <div class="debug-history-item">

                                    <strong>
                                        <?= h(
                                            $item['role']
                                            ?? ''
                                        ) ?>
                                    </strong>

                                    ：


                                    <?= h(
                                        $item['content']
                                        ?? ''
                                    ) ?>

                                </div>


                            <?php endforeach; ?>


                        <?php endif; ?>


                    </div>

                </div>


                <div class="debug-row">

                    <span class="debug-label">
                        現在の段階
                    </span>

                    <div class="debug-value">

                        Stage 2：
                        会話履歴を利用した文脈エンジン

                        <br><br>

                        ※これは機械学習ではありません。
                        PHPのルールを使って会話履歴との関連を推定しています。

                    </div>

                </div>


            </div>

        </details>


    <?php endif; ?>


    <!-- ======================================================
         入力エリア
         ====================================================== -->

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

/*
 * ============================================================
 * チャットを一番下へ
 * ============================================================
 */

const chat =
    document.getElementById('chat');

if (chat) {

    chat.scrollTop =
        chat.scrollHeight;
}


/*
 * ============================================================
 * Ctrl + Enter / Command + Enter
 * ============================================================
 */

const textarea =
    document.querySelector('textarea');

if (textarea) {

    textarea.addEventListener(
        'keydown',
        function(e) {

            if (
                (e.ctrlKey || e.metaKey) &&
                e.key === 'Enter'
            ) {

                this.form.submit();

            }

        }
    );

}

</script>


</body>

</html>

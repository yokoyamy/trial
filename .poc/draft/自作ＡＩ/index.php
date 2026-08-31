<?php
session_start();

/*
 * ============================================================
 * My AI - Context Engine Prototype
 * Apache + PHP / Database不要 / index.php 1ファイル
 *
 * 第2段階：
 * 「会話履歴を保存する」から
 * 「会話履歴を実際の回答生成に利用する」へ
 *
 * 注意：
 * これは機械学習・LLMではありません。
 * PHPで実装したルールベースの文脈エンジンです。
 * ============================================================
 */


/* ============================================================
 * 設定
 * ============================================================ */

const MAX_HISTORY = 30;
const CONTEXT_HISTORY_LIMIT = 12;
const DEBUG_MODE = true;


/* ============================================================
 * セッション初期化
 * ============================================================ */

if (!isset($_SESSION['messages']) || !is_array($_SESSION['messages'])) {
    $_SESSION['messages'] = [];
}

if (!isset($_SESSION['context']) || !is_array($_SESSION['context'])) {
    $_SESSION['context'] = [
        'topic' => '',
        'topic_keywords' => [],
        'last_intent' => '',
        'last_reference' => '',
    ];
}


/* ============================================================
 * 共通関数
 * ============================================================ */

/**
 * HTMLエスケープ
 */
function h($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/**
 * 日本語を含む文字列の長さ
 */
function textLength($text)
{
    return function_exists('mb_strlen')
        ? mb_strlen($text, 'UTF-8')
        : strlen($text);
}


/**
 * 日本語を含む部分文字列
 */
function textSubstr($text, $start, $length = null)
{
    if (function_exists('mb_substr')) {
        return $length === null
            ? mb_substr($text, $start, null, 'UTF-8')
            : mb_substr($text, $start, $length, 'UTF-8');
    }

    return $length === null
        ? substr($text, $start)
        : substr($text, $start, $length);
}


/**
 * 入力を正規化
 */
function normalizeInput($text)
{
    $text = trim((string)$text);

    // 改行・連続空白を整理
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace('/\n{3,}/u', "\n\n", $text);

    return trim($text);
}


/**
 * 履歴を直近N件に制限
 */
function getRecentMessages($messages, $limit = CONTEXT_HISTORY_LIMIT)
{
    if (!is_array($messages)) {
        return [];
    }

    if (count($messages) <= $limit) {
        return $messages;
    }

    return array_slice($messages, -$limit);
}


/**
 * ユーザー発言だけを取得
 */
function getUserMessages($messages)
{
    $result = [];

    foreach ($messages as $message) {
        if (
            isset($message['role']) &&
            $message['role'] === 'user' &&
            isset($message['content'])
        ) {
            $result[] = $message['content'];
        }
    }

    return $result;
}


/**
 * 直前のAI回答を取得
 */
function getLastAiMessage($messages)
{
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (
            isset($messages[$i]['role']) &&
            $messages[$i]['role'] === 'ai'
        ) {
            return $messages[$i]['content'];
        }
    }

    return '';
}


/**
 * 直前のユーザー発言を取得
 */
function getLastUserMessage($messages)
{
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (
            isset($messages[$i]['role']) &&
            $messages[$i]['role'] === 'user'
        ) {
            return $messages[$i]['content'];
        }
    }

    return '';
}


/* ============================================================
 * 意図推定
 * ============================================================ */

/**
 * ユーザー発言から意図を推定する。
 *
 * これはLLMによる意味理解ではなく、
 * 第2段階のモックとして文脈処理を実験するための
 * ルールベース分類。
 */
function detectIntent($message, $history)
{
    $message = normalizeInput($message);

    if ($message === '') {
        return 'empty';
    }

    // あいさつ
    if (preg_match(
        '/^(こんにちは|こんばんは|おはよう|おはようございます|やあ|どうも|hello|hi)[！!。.\s]*$/iu',
        $message
    )) {
        return 'greeting';
    }

    // 話題変更
    if (preg_match(
        '/(ところで|話は変わりますが|話を変えます|別の話|別件|ちなみに別|それはさておき)/u',
        $message
    )) {
        return 'topic_change';
    }

    // 同意・了承
    if (preg_match(
        '/^(はい|うん|そうです|そう|了解|わかりました|分かりました|お願いします|それで|それでいきましょう|その方法で|その方向で)[。！!？?]*$/u',
        $message
    )) {
        return 'agreement';
    }

    // 否定・訂正
    if (preg_match(
        '/(違います|ちがいます|そうではない|そうじゃない|間違い|訂正|ではありません|ではないです)/u',
        $message
    )) {
        return 'correction';
    }

    // 指示語を含む継続質問
    if (containsReferenceExpression($message) && !empty($history)) {
        return 'contextual_followup';
    }

    // 質問
    if (
        preg_match('/[？?]$/u', $message) ||
        preg_match(
            '/(なぜ|どうして|どうやって|どうすれば|何|なに|どのよう|どれ|いつ|誰|だれ|教えて|説明して|できますか|可能ですか|意味|とは)/u',
            $message
        )
    ) {
        return 'question';
    }

    // 方法・手順
    if (preg_match(
        '/(方法|手順|やり方|作り方|実装|設定|導入|使い方|進め方|どう作る|どう実装)/u',
        $message
    )) {
        return 'how_to';
    }

    // 説明要求
    if (preg_match(
        '/(詳しく|詳しく教えて|説明|解説|具体的に)/u',
        $message
    )) {
        return 'explanation';
    }

    // 通常の発言
    return 'statement';
}


/* ============================================================
 * 指示語・省略表現
 * ============================================================ */

/**
 * 文脈参照表現を含むか
 */
function containsReferenceExpression($message)
{
    return (bool)preg_match(
        '/(それ|これ|あれ|その|この|あの|前の|先ほどの|さっきの|直前の|上記の|その方法|その話|その件|それについて|これについて)/u',
        $message
    );
}


/**
 * 指示語を取得
 */
function extractReferenceExpressions($message)
{
    $expressions = [];

    $patterns = [
        'それについて',
        'これについて',
        'その方法',
        'その話',
        'その件',
        'それ',
        'これ',
        'あれ',
        'その',
        'この',
        'あの',
        '前の',
        '先ほどの',
        'さっきの',
        '直前の',
        '上記の',
    ];

    foreach ($patterns as $pattern) {
        if (mb_strpos($message, $pattern, 0, 'UTF-8') !== false) {
            $expressions[] = $pattern;
        }
    }

    return array_values(array_unique($expressions));
}


/**
 * 文脈上の参照対象を探す
 */
function resolveReference($message, $history, $currentTopic)
{
    $expressions = extractReferenceExpressions($message);

    if (empty($expressions)) {
        return [
            'found' => false,
            'expression' => '',
            'target' => '',
            'source' => '',
        ];
    }

    /*
     * 最優先：
     * 直前のAI回答
     */
    $lastAi = getLastAiMessage($history);

    if ($lastAi !== '') {
        return [
            'found' => true,
            'expression' => $expressions[0],
            'target' => summarizeText($lastAi, 180),
            'source' => '直前のAI回答',
        ];
    }

    /*
     * 次に：
     * 直前のユーザー発言
     */
    $lastUser = getLastUserMessage($history);

    if ($lastUser !== '') {
        return [
            'found' => true,
            'expression' => $expressions[0],
            'target' => summarizeText($lastUser, 180),
            'source' => '直前のユーザー発言',
        ];
    }

    /*
     * 最後に：
     * 現在の話題
     */
    if ($currentTopic !== '') {
        return [
            'found' => true,
            'expression' => $expressions[0],
            'target' => $currentTopic,
            'source' => '現在の話題',
        ];
    }

    return [
        'found' => false,
        'expression' => $expressions[0],
        'target' => '',
        'source' => '',
    ];
}


/* ============================================================
 * 話題推定
 * ============================================================ */

/**
 * 話題候補を抽出する。
 *
 * 第2段階では完全な自然言語理解ではなく、
 * 会話履歴から重要語を集めて話題候補を作る。
 */
function extractTopicCandidates($messages)
{
    $text = '';

    foreach ($messages as $message) {
        if (
            isset($message['role']) &&
            $message['role'] === 'user' &&
            isset($message['content'])
        ) {
            $text .= ' ' . $message['content'];
        }
    }

    $text = normalizeInput($text);

    if ($text === '') {
        return [];
    }

    $candidates = [];

    /*
     * プロジェクト固有の重要語。
     * 単なる回答トリガーではなく、
     * 話題を構成する語として利用する。
     */
    $knownTerms = [
        'AI',
        '人工知能',
        'PHP',
        'Apache',
        'データベース',
        'DB',
        'JSON',
        'TXT',
        'セッション',
        'API',
        'LLM',
        'プログラム',
        'プログラミング',
        '文脈',
        '会話履歴',
        '記憶',
        '知識',
        '学習',
        'モック',
        '自作AI',
    ];

    foreach ($knownTerms as $term) {
        if (mb_stripos($text, $term, 0, 'UTF-8') !== false) {
            $candidates[] = $term;
        }
    }

    /*
     * 長い日本語語句を少しだけ候補として拾う。
     */
    if (preg_match_all(
        '/[一-龠々ぁ-んァ-ヶーA-Za-z0-9]{3,}/u',
        $text,
        $matches
    )) {
        foreach ($matches[0] as $word) {
            if (textLength($word) >= 3) {
                $candidates[] = $word;
            }
        }
    }

    return array_values(array_unique($candidates));
}


/**
 * 現在の話題を推定する
 */
function inferTopic($currentMessage, $history, $previousContext)
{
    $intent = detectIntent($currentMessage, $history);

    /*
     * 明確な話題変更
     */
    if ($intent === 'topic_change') {
        $candidates = extractTopicCandidates([
            [
                'role' => 'user',
                'content' => $currentMessage
            ]
        ]);

        return [
            'topic' => buildTopicLabel($candidates, $currentMessage),
            'keywords' => $candidates,
            'changed' => true,
        ];
    }

    /*
     * 現在の発言から候補を抽出
     */
    $currentCandidates = extractTopicCandidates([
        [
            'role' => 'user',
            'content' => $currentMessage
        ]
    ]);

    /*
     * 現在の発言に明確な話題語がない場合、
     * 過去の文脈を継承する。
     */
    if (
        empty($currentCandidates) &&
        !empty($previousContext['topic'])
    ) {
        return [
            'topic' => $previousContext['topic'],
            'keywords' => $previousContext['topic_keywords'],
            'changed' => false,
        ];
    }

    /*
     * 履歴全体から候補を抽出
     */
    $recent = getRecentMessages(
        $history,
        CONTEXT_HISTORY_LIMIT
    );

    $historyCandidates = extractTopicCandidates($recent);

    /*
     * 現在の候補を優先しつつ履歴候補を追加
     */
    $keywords = array_values(array_unique(
        array_merge($currentCandidates, $historyCandidates)
    ));

    /*
     * 現在の発言が明らかな継続質問なら、
     * 前回の話題を優先する。
     */
    if (
        $intent === 'contextual_followup' &&
        !empty($previousContext['topic'])
    ) {
        return [
            'topic' => $previousContext['topic'],
            'keywords' => array_values(array_unique(
                array_merge(
                    $previousContext['topic_keywords'],
                    $keywords
                )
            )),
            'changed' => false,
        ];
    }

    return [
        'topic' => buildTopicLabel($keywords, $currentMessage),
        'keywords' => $keywords,
        'changed' => false,
    ];
}


/**
 * 話題名を作る
 */
function buildTopicLabel($keywords, $fallback)
{
    if (!empty($keywords)) {
        /*
         * よく出るプロジェクト用語を優先。
         */
        $priority = [
            '自作AI',
            'AI',
            'PHP',
            'Apache',
            'データベース',
            '文脈',
            '会話履歴',
            '記憶',
            '知識',
        ];

        foreach ($priority as $term) {
            if (in_array($term, $keywords, true)) {
                if ($term === 'PHP' || $term === 'AI') {
                    return $term . 'を使った自作AI';
                }

                return $term;
            }
        }

        return implode(' / ', array_slice($keywords, 0, 3));
    }

    /*
     * 話題が推定できない場合、
     * 入力そのものを話題名にしない。
     *
     * 長すぎる文章を「話題」と誤認するのを防ぐ。
     */
    if ($fallback !== '') {
        return '現在の会話';
    }

    return '';
}


/* ============================================================
 * 関連発言検索
 * ============================================================ */

/**
 * 現在の入力と関連性が高そうな過去発言を探す
 */
function findRelatedMessages($currentMessage, $history, $topicKeywords)
{
    $related = [];

    if (empty($history)) {
        return [];
    }

    foreach ($history as $index => $message) {
        if (
            !isset($message['role']) ||
            !isset($message['content'])
        ) {
            continue;
        }

        /*
         * 現在の発言を除外するため、
         * 内容が完全一致するものは除外。
         */
        if (
            $message['role'] === 'user' &&
            normalizeInput($message['content']) === normalizeInput($currentMessage)
        ) {
            continue;
        }

        $score = 0;

        /*
         * 話題キーワードとの一致
         */
        foreach ($topicKeywords as $keyword) {
            if (
                $keyword !== '' &&
                mb_stripos(
                    $message['content'],
                    $keyword,
                    0,
                    'UTF-8'
                ) !== false
            ) {
                $score += 2;
            }
        }

        /*
         * 指示語を含む場合は直近の発言を強く参照。
         */
        if (
            containsReferenceExpression($currentMessage)
        ) {
            $score += max(0, 10 - ($index / 2));
        }

        /*
         * 直近の発言を優先
         */
        $distance = count($history) - 1 - $index;

        if ($distance <= 3) {
            $score += 3;
        } elseif ($distance <= 7) {
            $score += 1;
        }

        if ($score > 0) {
            $related[] = [
                'index' => $index,
                'role' => $message['role'],
                'content' => $message['content'],
                'score' => $score,
            ];
        }
    }

    usort($related, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    return array_slice($related, 0, 5);
}


/* ============================================================
 * 文脈構築
 * ============================================================ */

/**
 * 回答生成に渡す文脈を構築する。
 */
function buildContext($currentMessage, $history)
{
    $previousContext = $_SESSION['context'];

    $intent = detectIntent(
        $currentMessage,
        $history
    );

    $topicInfo = inferTopic(
        $currentMessage,
        $history,
        $previousContext
    );

    $reference = resolveReference(
        $currentMessage,
        $history,
        $topicInfo['topic']
    );

    $related = findRelatedMessages(
        $currentMessage,
        $history,
        $topicInfo['keywords']
    );

    return [
        'current_message' => $currentMessage,
        'intent' => $intent,
        'topic' => $topicInfo['topic'],
        'topic_keywords' => $topicInfo['keywords'],
        'topic_changed' => $topicInfo['changed'],
        'reference' => $reference,
        'related_messages' => $related,
        'recent_messages' => getRecentMessages(
            $history,
            CONTEXT_HISTORY_LIMIT
        ),
        'last_user_message' => getLastUserMessage($history),
        'last_ai_message' => getLastAiMessage($history),
    ];
}


/* ============================================================
 * テーマ・意図に応じた回答生成
 * ============================================================ */

/**
 * 文脈を利用してモック回答を生成する。
 *
 * 重要：
 * この関数は $message だけではなく、
 * $context 全体を受け取る。
 *
 * これが第1段階との大きな違い。
 */
function generateContextualResponse($context)
{
    $message = $context['current_message'];
    $intent = $context['intent'];
    $topic = $context['topic'];
    $reference = $context['reference'];

    /*
     * --------------------------------------------------------
     * 1. あいさつ
     * --------------------------------------------------------
     */
    if ($intent === 'greeting') {
        if (!empty($topic)) {
            return
                "こんにちは！\n\n" .
                "現在は「" . $topic . "」についての会話として文脈を保持しています。\n" .
                "前の話を続けることも、新しい話題に移ることもできます。";
        }

        return
            "こんにちは！\n\n" .
            "私はApache＋PHPで作っている自作AIのプロトタイプです。\n" .
            "現在は第2段階として、会話履歴を利用した文脈処理を実験しています。";
    }


    /*
     * --------------------------------------------------------
     * 2. 文脈参照があるが対象を特定できない
     * --------------------------------------------------------
     */
    if (
        containsReferenceExpression($message) &&
        !$reference['found']
    ) {
        return
            "「" . $reference['expression'] . "」が何を指しているのか、" .
            "現在の会話履歴からは判断できません。\n\n" .
            "直前の話題や対象をもう少し具体的に教えてください。";
    }


    /*
     * --------------------------------------------------------
     * 3. 文脈を使った継続質問
     * --------------------------------------------------------
     */
    if ($intent === 'contextual_followup') {

        $target = $reference['target'];

        if ($target !== '') {
            /*
             * 「詳しく」「教えて」などの継続質問
             */
            if (preg_match(
                '/(詳しく|教えて|説明|解説|知りたい|どうすれば|どうやって)/u',
                $message
            )) {
                return
                    "前の会話を参照して回答します。\n\n" .
                    "「" . $reference['expression'] . "」は、" .
                    "直前の「" . $reference['source'] . "」にある\n" .
                    "「" . $target . "」を指していると解釈しました。\n\n" .
                    "現在のモックでは、このように会話履歴から参照対象を" .
                    "特定してから回答を組み立てています。\n\n" .
                    "次の段階では、この参照対象に対して実際の知識検索や" .
                    "LLMによる回答生成を接続できます。";
            }

            /*
             * 「その方法で」「それでいきましょう」
             */
            if ($intent === 'agreement') {
                return
                    "了解しました。\n\n" .
                    "「" . $reference['expression'] . "」を、" .
                    "直前の「" . $reference['source'] . "」にある内容として" .
                    "文脈上の対象に設定しました。\n\n" .
                    "現在の話題は「" . $topic . "」です。";
            }

            return
                "前の会話を参照しました。\n\n" .
                "「" . $reference['expression'] . "」は、" .
                "「" . $target . "」を指していると解釈しています。\n\n" .
                "現在の話題は「" . $topic . "」です。";
        }
    }


    /*
     * --------------------------------------------------------
     * 4. 話題変更
     * --------------------------------------------------------
     */
    if ($intent === 'topic_change') {
        return
            "話題が変わったと判断しました。\n\n" .
            "これまでの会話を完全に削除したわけではありませんが、" .
            "現在の発言を新しい話題として扱います。\n\n" .
            "現在の話題候補：\n" .
            "「" . ($topic !== '' ? $topic : '新しい話題') . "」";
    }


    /*
     * --------------------------------------------------------
     * 5. 訂正
     * --------------------------------------------------------
     */
    if ($intent === 'correction') {
        return
            "訂正として受け取りました。\n\n" .
            "これまでの文脈をそのまま正しいものとして固定せず、" .
            "今回の発言を優先して今後の文脈を更新します。\n\n" .
            "現在の話題：" .
            ($topic !== '' ? $topic : '未確定');
    }


    /*
     * --------------------------------------------------------
     * 6. 同意
     * --------------------------------------------------------
     */
    if ($intent === 'agreement') {
        if (!empty($context['last_ai_message'])) {
            return
                "了解しました。\n\n" .
                "直前のAI回答を受けての同意として解釈しました。\n" .
                "現在の話題は「" .
                ($topic !== '' ? $topic : '現在の会話') .
                "」です。\n\n" .
                "この状態では、次の質問も現在の話題に関連付けて処理します。";
        }

        return
            "了解しました。\n\n" .
            "ただし、まだ十分な会話履歴がないため、" .
            "具体的な対象は判断できません。";
    }


    /*
     * --------------------------------------------------------
     * 7. 自作AIそのものについて
     * --------------------------------------------------------
     */
    if (preg_match('/(自作AI|AI|人工知能)/iu', $message)) {

        if (
            $topic !== '' &&
            count($context['recent_messages']) > 1
        ) {
            return
                "会話履歴を踏まえて回答します。\n\n" .
                "今回の発言は、現在の「" . $topic . "」という話題と" .
                "関連していると判断しました。\n\n" .
                "このAIはまだ機械学習しているわけではありません。" .
                "過去の会話をセッションに保存し、それを文脈として" .
                "現在の回答生成へ利用しています。\n\n" .
                "つまり今実装しているのは「学習」ではなく、" .
                "「会話履歴を利用した文脈理解」です。";
        }

        return
            "このプロジェクトでは、まずAIの基本構造をPHPだけで作っています。\n\n" .
            "現在は「入力 → 会話履歴 → 文脈解析 → 意図推定 → 回答生成」" .
            "という流れを実装しています。\n\n" .
            "まだ機械学習やLLMは使っていません。";
    }


    /*
     * --------------------------------------------------------
     * 8. PHP・Apache・DB関連
     * --------------------------------------------------------
     */
    if (
        preg_match(
            '/(PHP|Apache|データベース|DB|JSON|セッション)/iu',
            $message
        )
    ) {
        return
            "会話履歴を参照して回答します。\n\n" .
            "現在の話題は「" .
            ($topic !== '' ? $topic : 'PHPで作る自作AI') .
            "」として扱っています。\n\n" .
            "この段階ではデータベースを使わず、PHPセッションを" .
            "短期的な会話記憶として利用しています。\n\n" .
            "将来的にはJSONなどのローカルファイルを利用して、" .
            "セッション終了後も保持できる記憶へ発展させる予定です。";
    }


    /*
     * --------------------------------------------------------
     * 9. 質問
     * --------------------------------------------------------
     */
    if ($intent === 'question') {

        if ($topic !== '') {
            return
                "質問を受け取りました。\n\n" .
                "現在の会話履歴から、話題を「" . $topic . "」として" .
                "認識しています。\n\n" .
                "質問：\n" .
                $message . "\n\n" .
                "第2段階のモックでは、ここで過去の関連発言を参照して" .
                "回答材料を組み立てています。\n\n" .
                "現時点では外部の知識源やLLMを接続していないため、" .
                "専門的な内容については正確な回答を保証できません。";
        }

        return
            "質問を受け取りました。\n\n" .
            "ただし、現在の会話履歴から明確な話題を特定できません。\n" .
            "もう少し対象を具体的にしてもらえれば、" .
            "文脈として関連付けて処理します。";
    }


    /*
     * --------------------------------------------------------
     * 10. 方法・手順
     * --------------------------------------------------------
     */
    if ($intent === 'how_to') {
        return
            "方法についての質問として受け取りました。\n\n" .
            "現在の話題：" .
            ($topic !== '' ? $topic : '未確定') . "\n\n" .
            "このAIでは、回答を決める前に会話履歴を確認し、" .
            "現在の発言と過去の発言を関連付けています。\n\n" .
            "第2段階では文脈処理までをPHPで実装し、" .
            "第3段階以降で記憶・知識・推論を追加していきます。";
    }


    /*
     * --------------------------------------------------------
     * 11. 説明要求
     * --------------------------------------------------------
     */
    if ($intent === 'explanation') {
        return
            "説明要求として認識しました。\n\n" .
            "現在の話題は「" .
            ($topic !== '' ? $topic : '現在の会話') .
            "」です。\n\n" .
            "直前までの会話を文脈として保持しているため、" .
            "現在の発言だけでなく、過去の発言との関係を考慮して" .
            "回答できるようになっています。\n\n" .
            "ただし、これは機械学習ではありません。";
    }


    /*
     * --------------------------------------------------------
     * 12. 通常発言
     * --------------------------------------------------------
     */
    if ($topic !== '') {
        return
            "発言を受け取りました。\n\n" .
            "会話履歴を確認したところ、現在の話題を\n" .
            "「" . $topic . "」として扱っています。\n\n" .
            "今回の発言：\n" .
            $message . "\n\n" .
            "この発言は現在の話題を継続するものとして、" .
            "次の会話でも参照できるように履歴へ保存しました。";
    }


    /*
     * --------------------------------------------------------
     * 13. 最終フォールバック
     * --------------------------------------------------------
     */
    return
        "発言を受け取りました。\n\n" .
        "まだ十分な文脈を形成できていないため、" .
        "今回の発言だけで内容を決めつけないようにしています。\n\n" .
        "もう少し会話を続けると、関連する発言を文脈として" .
        "利用できるようになります。";
}


/* ============================================================
 * 文脈状態更新
 * ============================================================ */

function updateContextState($context)
{
    $_SESSION['context'] = [
        'topic' => $context['topic'],
        'topic_keywords' => $context['topic_keywords'],
        'last_intent' => $context['intent'],
        'last_reference' => isset($context['reference']['target'])
            ? $context['reference']['target']
            : '',
    ];
}


/* ============================================================
 * 履歴保存
 * ============================================================ */

function saveMessage($role, $content)
{
    $_SESSION['messages'][] = [
        'role' => $role,
        'content' => $content,
        'time' => date('Y-m-d H:i:s'),
    ];

    /*
     * 履歴が無限に増えないようにする。
     */
    if (count($_SESSION['messages']) > MAX_HISTORY) {
        $_SESSION['messages'] = array_slice(
            $_SESSION['messages'],
            -MAX_HISTORY
        );
    }
}


/* ============================================================
 * 会話リセット
 * ============================================================ */

if (isset($_POST['reset'])) {

    $_SESSION['messages'] = [];

    $_SESSION['context'] = [
        'topic' => '',
        'topic_keywords' => [],
        'last_intent' => '',
        'last_reference' => '',
    ];

    header(
        'Location: ' .
        (isset($_SERVER['PHP_SELF'])
            ? $_SERVER['PHP_SELF']
            : 'index.php')
    );

    exit;
}


/* ============================================================
 * メッセージ送信
 * ============================================================ */

$debugContext = null;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['message'])
) {

    $userMessage = normalizeInput($_POST['message']);

    if ($userMessage !== '') {

        /*
         * AI回答を生成する前の履歴。
         *
         * 重要：
         * 現在のユーザー発言を保存した後ではなく、
         * 「過去の会話」として文脈解析する。
         */
        $historyBeforeCurrent = $_SESSION['messages'];

        /*
         * 文脈構築
         */
        $context = buildContext(
            $userMessage,
            $historyBeforeCurrent
        );

        /*
         * 回答生成
         */
        $aiMessage = generateContextualResponse(
            $context
        );

        /*
         * ユーザー発言を保存
         */
        saveMessage(
            'user',
            $userMessage
        );

        /*
         * AI回答を保存
         */
        saveMessage(
            'ai',
            $aiMessage
        );

        /*
         * 文脈状態更新
         */
        updateContextState($context);

        /*
         * 開発用表示
         */
        $debugContext = $context;
    }

    /*
     * POST再送信防止
     *
     * ただしDEBUG情報もセッションへ一時保存。
     */
    if (DEBUG_MODE && $debugContext !== null) {
        $_SESSION['last_debug_context'] = $debugContext;
    }

    header(
        'Location: ' .
        (isset($_SERVER['PHP_SELF'])
            ? $_SERVER['PHP_SELF']
            : 'index.php')
    );

    exit;
}


/* ============================================================
 * 表示用デバッグ情報
 * ============================================================ */

$lastDebugContext = null;

if (
    DEBUG_MODE &&
    isset($_SESSION['last_debug_context']) &&
    is_array($_SESSION['last_debug_context'])
) {
    $lastDebugContext = $_SESSION['last_debug_context'];

    /*
     * 一度表示したら削除。
     */
    unset($_SESSION['last_debug_context']);
}

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

.app {
    max-width: 900px;
    min-height: 100vh;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    background: #fff;
}

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
    border: 1px solid #ddd;
    background: #fff;
    border-radius: 8px;
    padding: 7px 12px;
    cursor: pointer;
}

.reset:hover {
    background: #f3f4f6;
}

.chat {
    flex: 1;
    overflow-y: auto;
    padding: 30px 20px;
}

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
    color: white;
    border-bottom-right-radius: 5px;
}

.ai .bubble {
    background: #f1f5f9;
    color: #222;
    border-bottom-left-radius: 5px;
}

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

.debug {
    margin-top: 20px;
    border: 1px solid #dbeafe;
    background: #eff6ff;
    border-radius: 12px;
    padding: 15px;
    font-size: 13px;
}

.debug h3 {
    margin-top: 0;
}

.debug-grid {
    display: grid;
    grid-template-columns: 140px 1fr;
    gap: 8px 12px;
}

.debug-label {
    color: #64748b;
    font-weight: bold;
}

.debug-value {
    overflow-wrap: anywhere;
}

.debug-related {
    margin: 10px 0 0;
    padding-left: 20px;
}

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
        0 0 0 3px rgba(37,99,235,.1);
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

@media (max-width: 600px) {

    .app {
        min-height: 100dvh;
    }

    .bubble {
        max-width: 88%;
    }

    .header {
        padding: 10px 12px;
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

    .debug-grid {
        grid-template-columns: 1fr;
        gap: 3px;
    }

}

</style>

</head>

<body>

<div class="app">

    <!-- =====================================================
         ヘッダー
         ===================================================== -->

    <header class="header">

        <div>

            <div class="logo">
                🤖 My AI
            </div>

            <div class="status">
                ● Online / Context Engine
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


    <!-- =====================================================
         チャット
         ===================================================== -->

    <main class="chat" id="chat">

        <?php if (empty($_SESSION['messages'])): ?>

            <div class="welcome">

                <h1>
                    こんにちは 👋
                </h1>

                <p>
                    PHPで作っている自作AIの第2段階です。
                </p>

                <p>
                    会話履歴を利用して文脈を考慮します。
                </p>

                <p>
                    例えば「それを詳しく教えて」のような
                    継続質問を試してください。
                </p>

            </div>

        <?php else: ?>

            <?php foreach ($_SESSION['messages'] as $message): ?>

                <?php if (
                    isset($message['role']) &&
                    $message['role'] === 'user'
                ): ?>

                    <div class="message user">

                        <div class="bubble">

                            <?= h(
                                isset($message['content'])
                                    ? $message['content']
                                    : ''
                            ) ?>

                        </div>

                    </div>

                <?php elseif (
                    isset($message['role']) &&
                    $message['role'] === 'ai'
                ): ?>

                    <div class="message ai">

                        <div class="ai-icon">
                            AI
                        </div>

                        <div class="bubble">

                            <?= h(
                                isset($message['content'])
                                    ? $message['content']
                                    : ''
                            ) ?>

                        </div>

                    </div>

                <?php endif; ?>

            <?php endforeach; ?>

        <?php endif; ?>


        <!-- =================================================
             開発用文脈情報
             ================================================= -->

        <?php if (
            DEBUG_MODE &&
            is_array($lastDebugContext)
        ): ?>

            <div class="debug">

                <h3>
                    🔎 Context Engine Debug
                </h3>

                <div class="debug-grid">

                    <div class="debug-label">
                        現在の話題
                    </div>

                    <div class="debug-value">
                        <?= h(
                            $lastDebugContext['topic']
                                ?: '未確定'
                        ) ?>
                    </div>


                    <div class="debug-label">
                        推定意図
                    </div>

                    <div class="debug-value">
                        <?= h(
                            $lastDebugContext['intent']
                        ) ?>
                    </div>


                    <div class="debug-label">
                        話題変更
                    </div>

                    <div class="debug-value">
                        <?= $lastDebugContext['topic_changed']
                            ? 'はい'
                            : 'いいえ' ?>
                    </div>


                    <div class="debug-label">
                        指示語
                    </div>

                    <div class="debug-value">
                        <?php
                        $refs =
                            extractReferenceExpressions(
                                $lastDebugContext[
                                    'current_message'
                                ]
                            );

                        echo h(
                            !empty($refs)
                                ? implode(' / ', $refs)
                                : 'なし'
                        );
                        ?>
                    </div>


                    <div class="debug-label">
                        参照対象
                    </div>

                    <div class="debug-value">

                        <?php if (
                            !empty(
                                $lastDebugContext[
                                    'reference'
                                ]['target']
                            )
                        ): ?>

                            <?= h(
                                $lastDebugContext[
                                    'reference'
                                ]['target']
                            ) ?>

                            <br>

                            <small>
                                参照元：
                                <?= h(
                                    $lastDebugContext[
                                        'reference'
                                    ]['source']
                                ) ?>
                            </small>

                        <?php else: ?>

                            なし

                        <?php endif; ?>

                    </div>


                    <div class="debug-label">
                        関連発言
                    </div>

                    <div class="debug-value">

                        <?php
                        $related =
                            $lastDebugContext[
                                'related_messages'
                            ];
                        ?>

                        <?php if (!empty($related)): ?>

                            <ul class="debug-related">

                                <?php foreach (
                                    $related
                                    as $item
                                ): ?>

                                    <li>

                                        <?= h(
                                            $item['role']
                                        ) ?>：

                                        <?= h(
                                            summarizeText(
                                                $item['content'],
                                                120
                                            )
                                        ) ?>

                                        （score:
                                        <?= h(
                                            $item['score']
                                        ) ?>）

                                    </li>

                                <?php endforeach; ?>

                            </ul>

                        <?php else: ?>

                            なし

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        <?php endif; ?>

    </main>


    <!-- =====================================================
         入力
         ===================================================== -->

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
 * チャットを一番下へ
 */
const chat =
    document.getElementById('chat');

if (chat) {
    chat.scrollTop =
        chat.scrollHeight;
}


/*
 * Ctrl + Enter / Command + Enter で送信
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
                e.preventDefault();
                this.form.submit();
            }

        }
    );

}

</script>

</body>

</html>

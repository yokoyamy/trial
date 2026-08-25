<?php
declare(strict_types=1);

/**
 * Survey Management Mock
 * Apache 2.4 / PHP 8.5
 * DBなし・外部API接続なし・実メール送信なし
 *
 * 保存先:
 *   survey_mock_data.json
 *
 * ※本番利用を想定した認証・CSRF・暗号化・権限制御・メール送信等は
 *   モック実装のため省略しています。
 */

session_start();
date_default_timezone_set('Asia/Tokyo');

const DATA_FILE = __DIR__ . '/survey_mock_data.json';

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function uid(string $prefix = 'id'): string
{
    return $prefix . '_' . bin2hex(random_bytes(5));
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function loadData(): array
{
    if (!file_exists(DATA_FILE)) {
        $data = seedData();
        saveData($data);
        return $data;
    }

    $json = file_get_contents(DATA_FILE);
    $data = json_decode($json ?: '', true);

    if (!is_array($data)) {
        $data = seedData();
        saveData($data);
    }

    $data['surveys'] ??= [];
    $data['groups'] ??= [];
    $data['questions'] ??= [];
    $data['choices'] ??= [];
    $data['customers'] ??= [];
    $data['answers'] ??= [];
    $data['sendHistories'] ??= [];
    $data['settings'] ??= [
        'kintone' => [],
        'smtp' => []
    ];

    return $data;
}

function saveData(array $data): void
{
    file_put_contents(
        DATA_FILE,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function seedData(): array
{
    $s1 = 'survey_001';
    $s2 = 'survey_002';

    $g1 = 'group_001';
    $g2 = 'group_002';
    $g3 = 'group_003';

    return [
        'surveys' => [
            [
                'id' => $s1,
                'title' => '2026年度 顧客満足度アンケート',
                'description' => 'サービスについてのご意見をお聞かせください。',
                'startAt' => '2026-08-01T09:00',
                'endAt' => '2026-09-30T23:59',
                'status' => 'published',
                'numberingMode' => 'survey',
                'createdAt' => '2026-07-20 10:00:00',
                'updatedAt' => '2026-08-20 15:00:00',
                'allowResubmission' => false,
            ],
            [
                'id' => $s2,
                'title' => '新サービス利用後アンケート',
                'description' => '新サービスをご利用いただいたお客様向けです。',
                'startAt' => '2026-08-10T10:00',
                'endAt' => '2026-10-31T23:59',
                'status' => 'draft',
                'numberingMode' => 'group',
                'createdAt' => '2026-08-01 10:00:00',
                'updatedAt' => '2026-08-21 11:00:00',
                'allowResubmission' => true,
            ],
        ],
        'groups' => [
            [
                'id' => $g1,
                'surveyId' => $s1,
                'title' => 'サービス全体について',
                'order' => 1,
            ],
            [
                'id' => $g2,
                'surveyId' => $s1,
                'title' => 'サポートについて',
                'order' => 2,
            ],
            [
                'id' => $g3,
                'surveyId' => $s2,
                'title' => '利用状況',
                'order' => 1,
            ],
        ],
        'questions' => [
            [
                'id' => 'question_001',
                'groupId' => $g1,
                'text' => 'サービス全体の満足度を教えてください。',
                'type' => 'single',
                'required' => true,
                'order' => 1,
                'branchRules' => [],
            ],
            [
                'id' => 'question_002',
                'groupId' => $g1,
                'text' => '良かった点を教えてください。',
                'type' => 'multiple',
                'required' => false,
                'order' => 2,
                'branchRules' => [],
            ],
            [
                'id' => 'question_003',
                'groupId' => $g1,
                'text' => 'ご意見・ご要望をご記入ください。',
                'type' => 'text',
                'required' => false,
                'order' => 3,
                'branchRules' => [],
            ],
            [
                'id' => 'question_004',
                'groupId' => $g2,
                'text' => 'サポートへの満足度を教えてください。',
                'type' => 'single',
                'required' => true,
                'order' => 1,
                'branchRules' => [],
            ],
            [
                'id' => 'question_005',
                'groupId' => $g3,
                'text' => '新サービスをどの程度利用しましたか？',
                'type' => 'single',
                'required' => true,
                'order' => 1,
                'branchRules' => [],
            ],
        ],
        'choices' => [
            ['id' => 'choice_001', 'questionId' => 'question_001', 'label' => '非常に満足', 'order' => 1, 'hasOther' => false],
            ['id' => 'choice_002', 'questionId' => 'question_001', 'label' => '満足', 'order' => 2, 'hasOther' => false],
            ['id' => 'choice_003', 'questionId' => 'question_001', 'label' => '普通', 'order' => 3, 'hasOther' => false],
            ['id' => 'choice_004', 'questionId' => 'question_001', 'label' => '不満', 'order' => 4, 'hasOther' => false],

            ['id' => 'choice_005', 'questionId' => 'question_002', 'label' => '操作性', 'order' => 1, 'hasOther' => false],
            ['id' => 'choice_006', 'questionId' => 'question_002', 'label' => '価格', 'order' => 2, 'hasOther' => false],
            ['id' => 'choice_007', 'questionId' => 'question_002', 'label' => 'サポート', 'order' => 3, 'hasOther' => false],

            ['id' => 'choice_008', 'questionId' => 'question_004', 'label' => '非常に満足', 'order' => 1, 'hasOther' => false],
            ['id' => 'choice_009', 'questionId' => 'question_004', 'label' => '満足', 'order' => 2, 'hasOther' => false],
            ['id' => 'choice_010', 'questionId' => 'question_004', 'label' => '普通', 'order' => 3, 'hasOther' => false],
            ['id' => 'choice_011', 'questionId' => 'question_004', 'label' => '不満', 'order' => 4, 'hasOther' => false],

            ['id' => 'choice_012', 'questionId' => 'question_005', 'label' => '毎日', 'order' => 1, 'hasOther' => false],
            ['id' => 'choice_013', 'questionId' => 'question_005', 'label' => '週数回', 'order' => 2, 'hasOther' => false],
            ['id' => 'choice_014', 'questionId' => 'question_005', 'label' => '数回程度', 'order' => 3, 'hasOther' => false],
        ],
        'customers' => [
            [
                'id' => 'customer_001',
                'organizationName' => '株式会社サンプル商事',
                'name' => '山田 太郎',
                'email' => 'taro@example.test',
                'department' => '営業部',
                'phone' => '03-0000-0001',
                'address' => '東京都港区',
                'kintoneStatus' => 'registered',
            ],
            [
                'id' => 'customer_002',
                'organizationName' => '株式会社東京テスト',
                'name' => '佐藤 花子',
                'email' => 'hanako@example.test',
                'department' => '総務部',
                'phone' => '03-0000-0002',
                'address' => '東京都千代田区',
                'kintoneStatus' => 'registered',
            ],
            [
                'id' => 'customer_003',
                'organizationName' => '株式会社デモサービス',
                'name' => '鈴木 一郎',
                'email' => 'ichiro@example.test',
                'department' => '企画部',
                'phone' => '03-0000-0003',
                'address' => '東京都新宿区',
                'kintoneStatus' => 'unregistered',
            ],
            [
                'id' => 'customer_004',
                'organizationName' => '合同会社テスト',
                'name' => '田中 次郎',
                'email' => 'jiro@example.test',
                'department' => '開発部',
                'phone' => '03-0000-0004',
                'address' => '東京都渋谷区',
                'kintoneStatus' => 'registered',
            ],
        ],
        'answers' => [
            [
                'id' => 'answer_001',
                'surveyId' => $s1,
                'customerId' => 'customer_001',
                'respondentInfo' => ['name' => '山田 太郎', 'email' => 'taro@example.test'],
                'answers' => [
                    'question_001' => '非常に満足',
                    'question_002' => ['操作性', 'サポート'],
                    'question_003' => '今後も利用したいです。',
                    'question_004' => '満足',
                ],
                'submittedAt' => '2026-08-15 12:30:00',
                'status' => 'submitted',
            ],
            [
                'id' => 'answer_002',
                'surveyId' => $s1,
                'customerId' => 'customer_002',
                'respondentInfo' => ['name' => '佐藤 花子', 'email' => 'hanako@example.test'],
                'answers' => [
                    'question_001' => '満足',
                    'question_002' => ['価格'],
                    'question_003' => '特にありません。',
                    'question_004' => '非常に満足',
                ],
                'submittedAt' => '2026-08-18 09:20:00',
                'status' => 'submitted',
            ],
        ],
        'sendHistories' => [],
        'settings' => [
            'kintone' => [
                'subdomain' => '',
                'appId' => '',
                'loginName' => '',
                'password' => '',
                'sslVerify' => true,
                'fieldMapping' => [],
                'addressFields' => [],
            ],
            'smtp' => [
                'server' => '',
                'port' => 587,
                'encryption' => 'TLS',
                'auth' => true,
                'username' => '',
                'password' => '',
                'from' => '',
                'senderName' => '',
                'replyTo' => '',
                'status' => '未設定',
            ],
        ],
    ];
}

$data = loadData();

/* -------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------- */

function findSurvey(array &$data, string $id): ?array
{
    foreach ($data['surveys'] as $survey) {
        if ($survey['id'] === $id) {
            return $survey;
        }
    }
    return null;
}

function surveyIndex(array &$data, string $id): int
{
    foreach ($data['surveys'] as $i => $survey) {
        if ($survey['id'] === $id) {
            return $i;
        }
    }
    return -1;
}

function surveyAnswerCount(array $data, string $surveyId): int
{
    return count(array_filter(
        $data['answers'],
        fn($a) => ($a['surveyId'] ?? '') === $surveyId && ($a['status'] ?? '') === 'submitted'
    ));
}

function statusLabel(string $status): string
{
    return [
        'draft' => '下書き',
        'published' => '公開中',
        'stopped' => '停止',
        'ended' => '終了',
    ][$status] ?? $status;
}

function statusClass(string $status): string
{
    return [
        'draft' => 'badge-draft',
        'published' => 'badge-published',
        'stopped' => 'badge-stopped',
        'ended' => 'badge-ended',
    ][$status] ?? '';
}

function typeLabel(string $type): string
{
    return [
        'single' => '単一選択',
        'multiple' => '複数選択',
        'text' => '自由記述',
    ][$type] ?? $type;
}

function surveyQuestions(array $data, string $surveyId): array
{
    $groups = array_filter($data['groups'], fn($g) => $g['surveyId'] === $surveyId);

    usort($groups, fn($a, $b) => $a['order'] <=> $b['order']);

    $result = [];

    foreach ($groups as $group) {
        $qs = array_filter(
            $data['questions'],
            fn($q) => $q['groupId'] === $group['id']
        );

        usort($qs, fn($a, $b) => $a['order'] <=> $b['order']);

        foreach ($qs as $q) {
            $q['_groupTitle'] = $group['title'];
            $q['_groupOrder'] = $group['order'];
            $result[] = $q;
        }
    }

    return $result;
}

function renumberSurvey(array &$data, string $surveyId): void
{
    $surveyIdx = surveyIndex($data, $surveyId);

    if ($surveyIdx < 0) {
        return;
    }

    $mode = $data['surveys'][$surveyIdx]['numberingMode'] ?? 'survey';

    $groups = array_values(array_filter(
        $data['groups'],
        fn($g) => $g['surveyId'] === $surveyId
    ));

    usort($groups, fn($a, $b) => $a['order'] <=> $b['order']);

    $number = 1;

    foreach ($groups as $group) {
        $qs = array_values(array_filter(
            $data['questions'],
            fn($q) => $q['groupId'] === $group['id']
        ));

        usort($qs, fn($a, $b) => $a['order'] <=> $b['order']);

        $groupNumber = 1;

        foreach ($qs as $q) {
            foreach ($data['questions'] as $qi => $storedQ) {
                if ($storedQ['id'] === $q['id']) {
                    $data['questions'][$qi]['number'] =
                        $mode === 'survey' ? $number : $groupNumber;

                    $number++;
                    $groupNumber++;
                    break;
                }
            }
        }
    }

    $number = 1;
    foreach ($data['questions'] as $qi => $q) {
        if ($q['groupId'] && !empty($q['number'])) {
            $number++;
        }
    }
}

function autoEndSurveys(array &$data): void
{
    $changed = false;
    $current = new DateTimeImmutable();

    foreach ($data['surveys'] as $i => $survey) {
        if (
            ($survey['status'] ?? '') === 'published' &&
            !empty($survey['endAt'])
        ) {
            try {
                $end = new DateTimeImmutable($survey['endAt']);

                if ($current > $end) {
                    $data['surveys'][$i]['status'] = 'ended';
                    $data['surveys'][$i]['updatedAt'] = now();
                    $changed = true;
                }
            } catch (Throwable) {
                // モックでは不正な日時を無視
            }
        }
    }

    if ($changed) {
        saveData($data);
    }
}

autoEndSurveys($data);

/* -------------------------------------------------------------
 * POST Actions
 * ------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* アンケート新規・編集保存 */
    if ($action === 'save_survey') {
        $id = trim((string)($_POST['id'] ?? ''));

        if ($id === '') {
            $id = uid('survey');

            $survey = [
                'id' => $id,
                'title' => trim((string)($_POST['title'] ?? '')),
                'description' => trim((string)($_POST['description'] ?? '')),
                'startAt' => trim((string)($_POST['startAt'] ?? '')),
                'endAt' => trim((string)($_POST['endAt'] ?? '')),
                'status' => 'draft',
                'numberingMode' => ($_POST['numberingMode'] ?? 'survey') === 'group' ? 'group' : 'survey',
                'createdAt' => now(),
                'updatedAt' => now(),
                'allowResubmission' => isset($_POST['allowResubmission']),
            ];

            $data['surveys'][] = $survey;

            $groupId = uid('group');
            $data['groups'][] = [
                'id' => $groupId,
                'surveyId' => $id,
                'title' => 'グループ1',
                'order' => 1,
            ];

            saveData($data);
        } else {
            $idx = surveyIndex($data, $id);

            if ($idx >= 0) {
                $data['surveys'][$idx]['title'] = trim((string)($_POST['title'] ?? ''));
                $data['surveys'][$idx]['description'] = trim((string)($_POST['description'] ?? ''));
                $data['surveys'][$idx]['startAt'] = trim((string)($_POST['startAt'] ?? ''));
                $data['surveys'][$idx]['endAt'] = trim((string)($_POST['endAt'] ?? ''));
                $data['surveys'][$idx]['numberingMode'] =
                    ($_POST['numberingMode'] ?? 'survey') === 'group'
                    ? 'group'
                    : 'survey';
                $data['surveys'][$idx]['allowResubmission'] =
                    isset($_POST['allowResubmission']);
                $data['surveys'][$idx]['updatedAt'] = now();

                saveData($data);
            }
        }

        redirect('?page=edit&id=' . urlencode($id) . '&saved=1');
    }

    /* 状態変更 */
    if ($action === 'change_status') {
        $id = (string)($_POST['id'] ?? '');
        $newStatus = (string)($_POST['newStatus'] ?? '');

        $idx = surveyIndex($data, $id);

        if ($idx >= 0) {
            $old = $data['surveys'][$idx]['status'];

            $allowed = [
                'draft' => ['published'],
                'published' => ['stopped'],
                'stopped' => ['published'],
                'ended' => [],
            ];

            if (
                $newStatus !== 'ended' &&
                in_array($newStatus, $allowed[$old] ?? [], true)
            ) {
                $data['surveys'][$idx]['status'] = $newStatus;
                $data['surveys'][$idx]['updatedAt'] = now();
                saveData($data);
            }
        }

        redirect('?page=edit&id=' . urlencode($id));
    }

    /* 削除 */
    if ($action === 'delete_survey') {
        $id = (string)($_POST['id'] ?? '');

        $data['surveys'] = array_values(array_filter(
            $data['surveys'],
            fn($s) => $s['id'] !== $id
        ));

        $groupIds = [];
        foreach ($data['groups'] as $g) {
            if ($g['surveyId'] === $id) {
                $groupIds[] = $g['id'];
            }
        }

        $data['groups'] = array_values(array_filter(
            $data['groups'],
            fn($g) => $g['surveyId'] !== $id
        ));

        $data['questions'] = array_values(array_filter(
            $data['questions'],
            fn($q) => !in_array($q['groupId'], $groupIds, true)
        ));

        $questionIds = array_map(
            fn($q) => $q['id'],
            $data['questions']
        );

        $data['choices'] = array_values(array_filter(
            $data['choices'],
            fn($c) => in_array($c['questionId'], $questionIds, true)
        ));

        $data['answers'] = array_values(array_filter(
            $data['answers'],
            fn($a) => $a['surveyId'] !== $id
        ));

        $data['sendHistories'] = array_values(array_filter(
            $data['sendHistories'],
            fn($h) => $h['surveyId'] !== $id
        ));

        saveData($data);
        redirect('?page=list&deleted=1');
    }

    /* 複製 */
    if ($action === 'duplicate_survey') {
        $id = (string)($_POST['id'] ?? '');
        $survey = findSurvey($data, $id);

        if ($survey) {
            $newId = uid('survey');

            $survey['id'] = $newId;
            $survey['title'] .= '（コピー）';
            $survey['status'] = 'draft';
            $survey['createdAt'] = now();
            $survey['updatedAt'] = now();

            $data['surveys'][] = $survey;

            $groupMap = [];
            $questionMap = [];

            foreach ($data['groups'] as $g) {
                if ($g['surveyId'] !== $id) {
                    continue;
                }

                $newGroupId = uid('group');
                $groupMap[$g['id']] = $newGroupId;

                $g['id'] = $newGroupId;
                $g['surveyId'] = $newId;

                $data['groups'][] = $g;
            }

            $originalQuestions = array_values(array_filter(
                $data['questions'],
                function ($q) use ($groupMap) {
                    return isset($groupMap[$q['groupId']]);
                }
            ));

            foreach ($originalQuestions as $q) {
                $oldQuestionId = $q['id'];
                $newQuestionId = uid('question');

                $questionMap[$oldQuestionId] = $newQuestionId;

                $q['id'] = $newQuestionId;
                $q['groupId'] = $groupMap[$q['groupId']];

                $data['questions'][] = $q;
            }

            foreach ($data['choices'] as $c) {
                if (isset($questionMap[$c['questionId']])) {
                    $c['id'] = uid('choice');
                    $c['questionId'] = $questionMap[$c['questionId']];
                    $data['choices'][] = $c;
                }
            }

            renumberSurvey($data, $newId);
            saveData($data);

            redirect('?page=edit&id=' . urlencode($newId) . '&duplicated=1');
        }
    }

    /* グループ追加 */
    if ($action === 'add_group') {
        $surveyId = (string)($_POST['surveyId'] ?? '');

        $orders = array_map(
            fn($g) => (int)$g['order'],
            array_filter($data['groups'], fn($g) => $g['surveyId'] === $surveyId)
        );

        $data['groups'][] = [
            'id' => uid('group'),
            'surveyId' => $surveyId,
            'title' => '新しいグループ',
            'order' => $orders ? max($orders) + 1 : 1,
        ];

        renumberSurvey($data, $surveyId);
        saveData($data);

        redirect('?page=edit&id=' . urlencode($surveyId));
    }

    /* グループ編集 */
    if ($action === 'update_group') {
        $surveyId = (string)($_POST['surveyId'] ?? '');
        $groupId = (string)($_POST['groupId'] ?? '');

        foreach ($data['groups'] as $i => $g) {
            if ($g['id'] === $groupId) {
                $data['groups'][$i]['title'] =
                    trim((string)($_POST['title'] ?? '')) ?: '無題のグループ';
            }
        }

        renumberSurvey($data, $surveyId);
        saveData($data);

        redirect('?page=edit&id=' . urlencode($surveyId));
    }

    /* グループ削除 */
    if ($action === 'delete_group') {
        $surveyId = (string)($_POST['surveyId'] ?? '');
        $groupId = (string)($_POST['groupId'] ?? '');

        $data['groups'] = array_values(array_filter(
            $data['groups'],
            fn($g) => $g['id'] !== $groupId
        ));

        $data['questions'] = array_values(array_filter(
            $data['questions'],
            fn($q) => $q['groupId'] !== $groupId
        ));

        renumberSurvey($data, $surveyId);
        saveData($data);

        redirect('?page=edit&id=' . urlencode($surveyId));
    }

    /* 質問追加 */
    if ($action === 'add_question') {
        $surveyId = (string)($_POST['surveyId'] ?? '');
        $groupId = (string)($_POST['groupId'] ?? '');

        $orders = array_map(
            fn($q) => (int)$q['order'],
            array_filter($data['questions'], fn($q) => $q['groupId'] === $groupId)
        );

        $questionId = uid('question');

        $data['questions'][] = [
            'id' => $questionId,
            'groupId' => $groupId,
            'text' => '新しい質問',
            'type' => 'single',
            'required' => false,
            'order' => $orders ? max($orders) + 1 : 1,
            'branchRules' => [],
        ];

        $data['choices'][] = [
            'id' => uid('choice'),
            'questionId' => $questionId,
            'label' => '選択肢1',
            'order' => 1,
            'hasOther' => false,
        ];

        $data['choices'][] = [
            'id' => uid('choice'),
            'questionId' => $questionId,
            'label' => '選択肢2',
            'order' => 2,
            'hasOther' => false,
        ];

        renumberSurvey($data, $surveyId);
        saveData($data);

        redirect('?page=edit&id=' . urlencode($surveyId));
    }

    /* 質問編集 */
    if ($action === 'update_question') {
        $surveyId = (string)($_POST['surveyId'] ?? '');
        $questionId = (string)($_POST['questionId'] ?? '');

        foreach ($data['questions'] as $i => $q) {
            if ($q['id'] === $questionId) {
                $data['questions'][$i]['text'] =
                    trim((string)($_POST['text'] ?? '')) ?: '無題の質問';

                $data['questions'][$i]['type'] =
                    in_array($_POST['type'] ?? '', ['single', 'multiple', 'text'], true)
                    ? $_POST['type']
                    : 'single';

                $data['questions'][$i]['required'] =
                    isset($_POST['required']);
            }
        }

        renumberSurvey($data, $surveyId);
        saveData($data);

        redirect('?page=edit&id=' . urlencode($surveyId));
    }

    /* 質問削除 */
    if ($action === 'delete_question') {
        $surveyId = (string)($_POST['surveyId'] ?? '');
        $questionId = (string)($_POST['questionId'] ?? '');

        $data['questions'] = array_values(array_filter(
            $data['questions'],
            fn($q) => $q['id'] !== $questionId
        ));

        $data['choices'] = array_values(array_filter(
            $data['choices'],
            fn($c) => $c['questionId'] !== $questionId
        ));

        renumberSurvey($data, $surveyId);
        saveData($data);

        redirect('?page=edit&id=' . urlencode($surveyId));
    }

    /* 質問移動 */
    if ($action === 'move_question') {
        $surveyId = (string)($_POST['surveyId'] ?? '');
        $questionId = (string)($_POST['questionId'] ?? '');
        $groupId = (string)($_POST['groupId'] ?? '');

        foreach ($data['questions'] as $i => $q) {
            if ($q['id'] === $questionId) {
                $data['questions'][$i]['groupId'] = $groupId;

                $same = array_filter(
                    $data['questions'],
                    fn($x) => $x['groupId'] === $groupId
                );

                $data['questions'][$i]['order'] = count($same);
                break;
            }
        }

        renumberSurvey($data, $surveyId);
        saveData($data);

        redirect('?page=edit&id=' . urlencode($surveyId));
    }

    /* 顧客メール送信 */
    if ($action === 'send_mail') {
        $surveyId = (string)($_POST['surveyId'] ?? '');
        $selected = $_POST['customers'] ?? [];

        if (!is_array($selected)) {
            $selected = [];
        }

        $subject = trim((string)($_POST['subject'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));

        $success = 0;
        $failure = 0;
        $recipients = [];
        $messages = [];

        foreach ($data['customers'] as $customer) {
            if (!in_array($customer['id'], $selected, true)) {
                continue;
            }

            $individualUrl =
                'https://example.test/survey.php?id=' .
                rawurlencode($surveyId) .
                '&customer=' .
                rawurlencode($customer['id']);

            $rendered = str_replace(
                ['{{customerName}}', '{{surveyUrl}}'],
                [$customer['name'], $individualUrl],
                $body
            );

            $isFailure = str_contains($customer['email'], 'fail');

            if ($isFailure) {
                $failure++;
                $messages[] = [
                    'customerId' => $customer['id'],
                    'customerName' => $customer['name'],
                    'status' => 'failure',
                    'message' => 'モック送信エラー',
                    'url' => $individualUrl,
                    'body' => $rendered,
                ];
            } else {
                $success++;
                $recipients[] = $customer['email'];
                $messages[] = [
                    'customerId' => $customer['id'],
                    'customerName' => $customer['name'],
                    'status' => 'success',
                    'message' => '送信成功',
                    'url' => $individualUrl,
                    'body' => $rendered,
                ];
            }
        }

        $data['sendHistories'][] = [
            'id' => uid('history'),
            'surveyId' => $surveyId,
            'sentAt' => now(),
            'sendType' => '一括送信',
            'count' => count($selected),
            'subject' => $subject,
            'operator' => 'デモ管理者',
            'recipients' => $recipients,
            'messages' => $messages,
            'success' => $success,
            'failure' => $failure,
        ];

        saveData($data);

        redirect(
            '?page=send&id=' .
            urlencode($surveyId) .
            '&sent=1&success=' .
            $success .
            '&failure=' .
            $failure
        );
    }

    /* kintone保存 */
    if ($action === 'save_kintone') {
        $data['settings']['kintone'] = [
            'subdomain' => trim((string)($_POST['subdomain'] ?? '')),
            'appId' => trim((string)($_POST['appId'] ?? '')),
            'loginName' => trim((string)($_POST['loginName'] ?? '')),
            'password' => trim((string)($_POST['password'] ?? '')),
            'sslVerify' => isset($_POST['sslVerify']),
            'fieldMapping' => $_POST['fieldMapping'] ?? [],
            'addressFields' => $_POST['addressFields'] ?? [],
        ];

        saveData($data);
        redirect('?page=kintone&saved=1');
    }

    /* kintone操作 */
    if ($action === 'test_kintone') {
        $_SESSION['kintone_test'] = 'success';
        redirect('?page=kintone&tested=1');
    }

    if ($action === 'fetch_kintone_fields') {
        $_SESSION['kintone_fields'] = 'success';
        redirect('?page=kintone&fields=1');
    }

    if ($action === 'sync_kintone') {
        $_SESSION['kintone_sync'] = 'success';
        redirect('?page=kintone&sync=1');
    }

    /* SMTP保存 */
    if ($action === 'save_smtp') {
        $data['settings']['smtp'] = [
            'server' => trim((string)($_POST['server'] ?? '')),
            'port' => (int)($_POST['port'] ?? 587),
            'encryption' => $_POST['encryption'] ?? 'TLS',
            'auth' => isset($_POST['auth']),
            'username' => trim((string)($_POST['username'] ?? '')),
            'password' => trim((string)($_POST['password'] ?? '')),
            'from' => trim((string)($_POST['from'] ?? '')),
            'senderName' => trim((string)($_POST['senderName'] ?? '')),
            'replyTo' => trim((string)($_POST['replyTo'] ?? '')),
            'status' => '未設定',
        ];

        saveData($data);
        redirect('?page=smtp&saved=1');
    }

    if ($action === 'test_smtp') {
        $data['settings']['smtp']['status'] = '成功';
        saveData($data);
        redirect('?page=smtp&tested=1');
    }
}

/* -------------------------------------------------------------
 * Public respondent flow
 * ------------------------------------------------------------- */

$page = $_GET['page'] ?? 'list';

if ($page === 'respond') {
    renderRespondent($data);
    exit;
}

if ($page === 'confirm') {
    renderRespondent($data, true);
    exit;
}

if ($page === 'complete') {
    renderRespondentComplete();
    exit;
}

/* -------------------------------------------------------------
 * Admin page data
 * ------------------------------------------------------------- */

$surveyId = (string)($_GET['id'] ?? '');

function adminHeader(string $title, string $active = ''): void
{
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> | Survey Mock</title>
<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --bg:#f5f7fb;
    --surface:#fff;
    --text:#1f2937;
    --muted:#6b7280;
    --border:#dbe1ea;
    --danger:#dc2626;
    --success:#16a34a;
    --warning:#d97706;
    --shadow:0 2px 10px rgba(15,23,42,.06);
}
*{box-sizing:border-box}
body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Kaku Gothic ProN","Yu Gothic",Meiryo,sans-serif;
    line-height:1.6;
}
a{color:var(--primary);text-decoration:none}
button,input,textarea,select{font:inherit}
button{cursor:pointer}
.topbar{
    height:64px;
    background:#172033;
    color:#fff;
    display:flex;
    align-items:center;
    padding:0 24px;
    gap:28px;
}
.logo{font-weight:700;font-size:18px;white-space:nowrap}
.nav{display:flex;gap:6px;flex:1}
.nav a{
    color:#d9e0ed;
    padding:9px 13px;
    border-radius:7px;
    font-size:14px;
}
.nav a:hover,.nav a.active{background:#29354b;color:#fff}
.logout{
    color:#d9e0ed;
    font-size:13px;
}
.container{
    width:min(1440px,calc(100% - 40px));
    margin:26px auto 60px;
}
.page-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:20px;
}
.page-head h1{margin:0 0 5px;font-size:25px}
.page-head p{margin:0;color:var(--muted);font-size:14px}
.card{
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:10px;
    box-shadow:var(--shadow);
    padding:20px;
    margin-bottom:18px;
}
.toolbar{
    display:flex;
    flex-wrap:wrap;
    align-items:end;
    gap:12px;
}
.field{display:flex;flex-direction:column;gap:5px}
.field label,.form-label{
    font-size:13px;
    font-weight:600;
    color:#374151;
}
input[type=text],input[type=email],input[type=password],input[type=number],
input[type=datetime-local],textarea,select{
    border:1px solid #cbd5e1;
    border-radius:7px;
    padding:9px 11px;
    background:#fff;
    color:var(--text);
    min-height:40px;
}
textarea{min-height:110px;resize:vertical}
input:focus,textarea:focus,select:focus{
    outline:3px solid rgba(37,99,235,.12);
    border-color:var(--primary);
}
.btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    min-height:40px;
    padding:8px 15px;
    border-radius:7px;
    border:1px solid #cbd5e1;
    background:#fff;
    color:#374151;
    font-weight:600;
    font-size:14px;
}
.btn:hover{background:#f8fafc}
.btn-primary{background:var(--primary);color:#fff;border-color:var(--primary)}
.btn-primary:hover{background:var(--primary-dark)}
.btn-danger{background:#fff;color:var(--danger);border-color:#fecaca}
.btn-success{background:var(--success);color:#fff;border-color:var(--success)}
.btn-warning{background:#fff7ed;color:#9a3412;border-color:#fed7aa}
.btn-sm{min-height:34px;padding:5px 10px;font-size:13px}
.btn:disabled{opacity:.45;cursor:not-allowed}
.actions{display:flex;flex-wrap:wrap;gap:8px}
.alert{
    border-radius:8px;
    padding:11px 14px;
    margin-bottom:16px;
    font-size:14px;
}
.alert-success{background:#ecfdf3;color:#166534;border:1px solid #bbf7d0}
.alert-info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.alert-warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.alert-danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:900px}
th,td{
    border-bottom:1px solid #e5e7eb;
    padding:12px 10px;
    text-align:left;
    vertical-align:middle;
    font-size:14px;
}
th{background:#f8fafc;color:#475569;font-size:13px;white-space:nowrap}
tr:hover td{background:#fafcff}
.badge{
    display:inline-flex;
    padding:3px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}
.badge-draft{background:#f1f5f9;color:#475569}
.badge-published{background:#dcfce7;color:#166534}
.badge-stopped{background:#fef3c7;color:#92400e}
.badge-ended{background:#fee2e2;color:#991b1b}
.muted{color:var(--muted)}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
.form-grid .full{grid-column:1/-1}
.stat{
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    padding:17px;
}
.stat-label{font-size:13px;color:var(--muted)}
.stat-value{font-size:28px;font-weight:700;margin-top:4px}
.tabs{
    display:flex;
    gap:0;
    border-bottom:1px solid var(--border);
    margin:-20px -20px 20px;
    padding:0 20px;
    overflow-x:auto;
}
.tabs a{
    padding:13px 17px;
    color:#64748b;
    white-space:nowrap;
    border-bottom:3px solid transparent;
}
.tabs a.active{color:var(--primary);border-bottom-color:var(--primary);font-weight:700}
.section-title{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin:0 0 13px;
}
.section-title h2{font-size:18px;margin:0}
.group{
    border:1px solid var(--border);
    border-radius:9px;
    margin-bottom:16px;
    overflow:hidden;
    background:#fff;
}
.group-head{
    background:#f8fafc;
    padding:12px 15px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
}
.question{
    padding:15px;
    border-top:1px solid #e5e7eb;
}
.question-head{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:10px;
}
.q-number{
    width:34px;height:34px;border-radius:50%;
    background:#dbeafe;color:#1d4ed8;
    display:flex;align-items:center;justify-content:center;
    font-weight:700;flex:none;
}
.q-body{flex:1}
.q-text{font-weight:600}
.choice-list{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.choice-chip{
    background:#f1f5f9;border:1px solid #e2e8f0;
    padding:4px 9px;border-radius:6px;font-size:13px;
}
.drag-hint{font-size:12px;color:#94a3b8}
.chart{
    display:flex;
    flex-direction:column;
    gap:8px;
}
.bar-row{
    display:grid;
    grid-template-columns:140px 1fr 70px;
    gap:10px;
    align-items:center;
    font-size:13px;
}
.bar-bg{height:20px;background:#e5e7eb;border-radius:5px;overflow:hidden}
.bar-fill{height:100%;background:#3b82f6}
.mail-preview{
    background:#f8fafc;
    border:1px solid var(--border);
    padding:14px;
    border-radius:8px;
    white-space:pre-wrap;
}
.status-line{
    display:flex;
    align-items:center;
    gap:10px;
}
.result-number{font-size:26px;font-weight:700}
.result-success{color:#15803d}
.result-failure{color:#b91c1c}
.kv{
    display:grid;
    grid-template-columns:180px 1fr;
    border-top:1px solid #e5e7eb;
}
.kv div{padding:10px;border-bottom:1px solid #e5e7eb}
.kv div:nth-child(odd){font-weight:600;background:#f8fafc}
.empty{
    text-align:center;
    padding:50px 20px;
    color:var(--muted);
}
.modal{
    position:fixed;
    inset:0;
    display:none;
    align-items:center;
    justify-content:center;
    background:rgba(15,23,42,.52);
    z-index:1000;
    padding:20px;
}
.modal.open{display:flex}
.modal-box{
    width:min(560px,100%);
    background:#fff;
    border-radius:11px;
    box-shadow:0 20px 60px rgba(0,0,0,.25);
    padding:22px;
}
.modal-box h3{margin:0 0 10px}
.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:20px}
.sticky-actions{
    position:sticky;
    top:0;
    z-index:20;
    background:rgba(255,255,255,.96);
    backdrop-filter:blur(5px);
    padding:10px;
    border:1px solid var(--border);
    border-radius:9px;
    margin-bottom:18px;
}
.checkbox-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}
.checkbox-item{
    border:1px solid var(--border);
    padding:10px;
    border-radius:7px;
}
.answer-detail{
    border:1px solid var(--border);
    border-radius:8px;
    margin-bottom:10px;
    overflow:hidden;
}
.answer-detail-head{
    background:#f8fafc;
    padding:10px 13px;
    font-weight:600;
}
.answer-detail-body{padding:13px}
@media(max-width:900px){
    .topbar{height:auto;min-height:64px;flex-wrap:wrap;padding:12px 15px}
    .nav{order:3;width:100%;overflow-x:auto}
    .container{width:calc(100% - 24px);margin:16px auto 40px}
    .grid-2,.grid-3,.form-grid{grid-template-columns:1fr}
    .page-head{flex-direction:column}
}
@media(max-width:600px){
    .topbar{gap:12px}
    .logo{font-size:16px}
    .nav a{font-size:12px;padding:7px 9px}
    .card{padding:14px}
    .tabs{margin:-14px -14px 14px;padding:0 10px}
    .bar-row{grid-template-columns:95px 1fr 45px}
    .kv{grid-template-columns:1fr}
    .kv div:nth-child(odd){border-bottom:0}
    .actions .btn{flex:1}
}
</style>
</head>
<body>
<header class="topbar">
    <div class="logo">Survey Manager</div>
    <nav class="nav">
        <a class="<?= $active === 'list' ? 'active' : '' ?>" href="?page=list">アンケート一覧</a>
        <a class="<?= $active === 'kintone' ? 'active' : '' ?>" href="?page=kintone">kintone連携設定</a>
        <a class="<?= $active === 'smtp' ? 'active' : '' ?>" href="?page=smtp">メールサーバ設定</a>
    </nav>
    <a class="logout" href="?page=list&logout=1">ログアウト</a>
</header>
<?php
}

function adminFooter(): void
{
?>
<div class="modal" id="commonModal">
    <div class="modal-box">
        <h3 id="modalTitle">確認</h3>
        <div id="modalMessage"></div>
        <div class="modal-actions">
            <button class="btn" type="button" onclick="closeModal()">キャンセル</button>
            <button class="btn btn-primary" type="button" id="modalExecute">実行</button>
        </div>
    </div>
</div>

<script>
let modalAction = null;

function openModal(title, message, action) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalMessage').innerHTML = message;
    modalAction = action;
    document.getElementById('commonModal').classList.add('open');
}

function closeModal() {
    document.getElementById('commonModal').classList.remove('open');
    modalAction = null;
}

document.getElementById('modalExecute').addEventListener('click', function(){
    if (modalAction) modalAction();
    closeModal();
});

function submitConfirmed(formId, title, message) {
    openModal(title, message, function(){
        document.getElementById(formId).submit();
    });
}

function confirmSubmit(formId, title, message) {
    submitConfirmed(formId, title, message);
}

function dirtyConfirm(url) {
    openModal(
        '変更を破棄しますか？',
        '<p>入力した変更内容は保存されません。</p>',
        function(){ location.href = url; }
    );
}

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') closeModal();
});
</script>
</body>
</html>
<?php
}

/* -------------------------------------------------------------
 * List
 * ------------------------------------------------------------- */

function renderList(array &$data): void
{
    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $status = (string)($_GET['status'] ?? '');
    $sort = (string)($_GET['sort'] ?? 'updated');

    $surveys = $data['surveys'];

    if ($keyword !== '') {
        $surveys = array_filter(
            $surveys,
            fn($s) => mb_stripos($s['title'], $keyword) !== false
        );
    }

    if ($status !== '') {
        $surveys = array_filter(
            $surveys,
            fn($s) => $s['status'] === $status
        );
    }

    usort($surveys, function($a, $b) use ($sort, $data) {
        if ($sort === 'answers') {
            return surveyAnswerCount($data, $b['id']) <=> surveyAnswerCount($data, $a['id']);
        }

        if ($sort === 'start') {
            return strcmp($b['startAt'], $a['startAt']);
        }

        return strcmp($b['updatedAt'], $a['updatedAt']);
    });

    adminHeader('アンケート一覧', 'list');
?>
<main class="container">
    <div class="page-head">
        <div>
            <h1>アンケート一覧</h1>
            <p>登録済みアンケートを管理します。</p>
        </div>
        <a class="btn btn-primary" href="?page=edit">＋ アンケート作成</a>
    </div>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">アンケートを削除しました。</div>
    <?php endif; ?>

    <div class="card">
        <form method="get">
            <input type="hidden" name="page" value="list">
            <div class="toolbar">
                <div class="field" style="min-width:280px">
                    <label>タイトル検索</label>
                    <input
                        type="text"
                        name="keyword"
                        value="<?= h($keyword) ?>"
                        placeholder="タイトルを入力"
                        onkeydown="if(event.key==='Enter'){this.form.submit();}"
                    >
                </div>

                <div class="field">
                    <label>ステータス</label>
                    <select name="status">
                        <option value="">すべて</option>
                        <?php foreach ([
                            'draft' => '下書き',
                            'published' => '公開中',
                            'stopped' => '停止',
                            'ended' => '終了'
                        ] as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= $status === $value ? 'selected' : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>ソート</label>
                    <select name="sort">
                        <option value="updated" <?= $sort === 'updated' ? 'selected' : '' ?>>更新日（新しい順）</option>
                        <option value="answers" <?= $sort === 'answers' ? 'selected' : '' ?>>回答数（多い順）</option>
                        <option value="start" <?= $sort === 'start' ? 'selected' : '' ?>>開始日（新しい順）</option>
                    </select>
                </div>

                <button class="btn btn-primary" type="submit">検索</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>作成日／更新日</th>
                    <th>タイトル</th>
                    <th>アンケート期間</th>
                    <th>ステータス</th>
                    <th>回答数</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$surveys): ?>
                    <tr><td colspan="6"><div class="empty">該当するアンケートはありません。</div></td></tr>
                <?php endif; ?>

                <?php foreach ($surveys as $survey): ?>
                    <tr>
                        <td>
                            <?= h($survey['createdAt']) ?><br>
                            <span class="muted">更新 <?= h($survey['updatedAt']) ?></span>
                        </td>
                        <td>
                            <strong><?= h($survey['title']) ?></strong><br>
                            <span class="muted"><?= h($survey['id']) ?></span>
                        </td>
                        <td>
                            <?= h($survey['startAt']) ?><br>
                            ～ <?= h($survey['endAt']) ?>
                        </td>
                        <td>
                            <span class="badge <?= h(statusClass($survey['status'])) ?>">
                                <?= h(statusLabel($survey['status'])) ?>
                            </span>
                        </td>
                        <td><?= surveyAnswerCount($data, $survey['id']) ?> 件</td>
                        <td>
                            <div class="actions">
                                <a class="btn btn-sm" href="?page=edit&id=<?= urlencode($survey['id']) ?>">確認・編集</a>

                                <a class="btn btn-sm" href="?page=analysis&id=<?= urlencode($survey['id']) ?>">
                                    集計
                                </a>

                                <a class="btn btn-sm" href="?page=send&id=<?= urlencode($survey['id']) ?>">
                                    送信
                                </a>

                                <form
                                    method="post"
                                    id="duplicate_<?= h($survey['id']) ?>"
                                    style="display:inline"
                                >
                                    <input type="hidden" name="action" value="duplicate_survey">
                                    <input type="hidden" name="id" value="<?= h($survey['id']) ?>">
                                    <button
                                        class="btn btn-sm"
                                        type="button"
                                        onclick="confirmSubmit(
                                            'duplicate_<?= h($survey['id']) ?>',
                                            'アンケートを複製',
                                            '<p>「<?= h($survey['title']) ?>」を複製します。</p><p>複製後は下書きになります。回答データと送信履歴は複製されません。</p>'
                                        )"
                                    >複製</button>
                                </form>

                                <form
                                    method="post"
                                    id="delete_<?= h($survey['id']) ?>"
                                    style="display:inline"
                                >
                                    <input type="hidden" name="action" value="delete_survey">
                                    <input type="hidden" name="id" value="<?= h($survey['id']) ?>">
                                    <button
                                        class="btn btn-sm btn-danger"
                                        type="button"
                                        onclick="confirmSubmit(
                                            'delete_<?= h($survey['id']) ?>',
                                            'アンケートを削除',
                                            '<p>「<?= h($survey['title']) ?>」を削除します。</p><p><strong>回答データ・送信履歴も削除されます。この操作は元に戻せません。</strong></p>'
                                        )"
                                    >削除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php
    adminFooter();
}

/* -------------------------------------------------------------
 * Edit
 * ------------------------------------------------------------- */

function renderEdit(array &$data, string $id): void
{
    $survey = $id ? findSurvey($data, $id) : null;

    if ($id && !$survey) {
        redirect('?page=list');
    }

    $isNew = !$survey;

    if (!$survey) {
        $survey = [
            'id' => '',
            'title' => '',
            'description' => '',
            'startAt' => '',
            'endAt' => '',
            'status' => 'draft',
            'numberingMode' => 'survey',
            'createdAt' => '',
            'updatedAt' => '',
            'allowResubmission' => false,
        ];
    }

    $groups = array_values(array_filter(
        $data['groups'],
        fn($g) => $g['surveyId'] === $survey['id']
    ));

    usort($groups, fn($a, $b) => $a['order'] <=> $b['order']);

    $questionsByGroup = [];

    foreach ($groups as $group) {
        $qs = array_values(array_filter(
            $data['questions'],
            fn($q) => $q['groupId'] === $group['id']
        ));

        usort($qs, fn($a, $b) => $a['order'] <=> $b['order']);

        $questionsByGroup[$group['id']] = $qs;
    }

    $canChangeStatus = !$isNew && $survey['status'] !== 'ended';

    adminHeader($isNew ? 'アンケート作成' : 'アンケート編集', 'list');
?>
<main class="container">
    <div class="page-head">
        <div>
            <h1><?= $isNew ? 'アンケート作成' : 'アンケート編集' ?></h1>
            <?php if (!$isNew): ?>
                <p><?= h($survey['title']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success">保存しました。一覧へ戻ります。</div>
    <?php endif; ?>

    <?php if (isset($_GET['duplicated'])): ?>
        <div class="alert alert-success">アンケートを複製しました。状態は下書きです。</div>
    <?php endif; ?>

    <div class="sticky-actions">
        <div class="actions">
            <button
                class="btn"
                type="button"
                onclick="<?= $isNew
                    ? "location.href='?page=list'"
                    : "dirtyConfirm('?page=list')"
                ?>"
            >キャンセル</button>

            <button
                class="btn btn-primary"
                type="submit"
                form="surveyForm"
            >保存して一覧へ</button>

            <?php if (!$isNew): ?>
                <form method="post" id="statusForm" style="margin-left:auto">
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="id" value="<?= h($survey['id']) ?>">
                    <div class="status-line">
                        <span class="muted">状態</span>
                        <select
                            name="newStatus"
                            <?= $canChangeStatus ? '' : 'disabled' ?>
                            onchange="statusChanged(this)"
                        >
                            <?php
                            $options = [
                                'draft' => ['draft', 'published'],
                                'published' => ['published', 'stopped'],
                                'stopped' => ['stopped', 'published'],
                            ];

                            foreach ($options[$survey['status']] ?? [] as $s):
                            ?>
                                <option value="<?= h($s) ?>" <?= $s === $survey['status'] ? 'selected' : '' ?>>
                                    <?= h(statusLabel($s)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <form method="post" id="surveyForm">
            <input type="hidden" name="action" value="save_survey">
            <input type="hidden" name="id" value="<?= h($survey['id']) ?>">

            <div class="form-grid">
                <div class="field full">
                    <label>タイトル *</label>
                    <input type="text" name="title" required value="<?= h($survey['title']) ?>">
                </div>

                <div class="field full">
                    <label>説明</label>
                    <textarea name="description"><?= h($survey['description']) ?></textarea>
                </div>

                <div class="field">
                    <label>開始日時</label>
                    <input type="datetime-local" name="startAt" value="<?= h($survey['startAt']) ?>">
                </div>

                <div class="field">
                    <label>終了日時</label>
                    <input type="datetime-local" name="endAt" value="<?= h($survey['endAt']) ?>">
                </div>

                <div class="field">
                    <label>質問番号の採番方式</label>
                    <select name="numberingMode">
                        <option value="survey" <?= $survey['numberingMode'] === 'survey' ? 'selected' : '' ?>>
                            アンケート全体で通番
                        </option>
                        <option value="group" <?= $survey['numberingMode'] === 'group' ? 'selected' : '' ?>>
                            グループ毎に採番
                        </option>
                    </select>
                </div>

                <div class="field">
                    <label>再回答</label>
                    <label style="font-weight:400">
                        <input
                            type="checkbox"
                            name="allowResubmission"
                            <?= !empty($survey['allowResubmission']) ? 'checked' : '' ?>
                        >
                        回答済みURLからの再回答を許可する
                    </label>
                </div>
            </div>
        </form>
    </div>

    <?php if (!$isNew): ?>
        <div class="card">
            <div class="section-title">
                <h2>質問・グループ</h2>
                <form method="post">
                    <input type="hidden" name="action" value="add_group">
                    <input type="hidden" name="surveyId" value="<?= h($survey['id']) ?>">
                    <button class="btn btn-primary btn-sm">＋ グループ追加</button>
                </form>
            </div>

            <?php if (!$groups): ?>
                <div class="empty">
                    グループがありません。
                </div>
            <?php endif; ?>

            <?php foreach ($groups as $group): ?>
                <div class="group">
                    <div class="group-head">
                        <div>
                            <strong>グループ <?= h($group['order']) ?>：</strong>
                            <?= h($group['title']) ?>
                            <span class="drag-hint">（ドラッグ＆ドロップ対象）</span>
                        </div>

                        <div class="actions">
                            <form method="post">
                                <input type="hidden" name="action" value="update_group">
                                <input type="hidden" name="surveyId" value="<?= h($survey['id']) ?>">
                                <input type="hidden" name="groupId" value="<?= h($group['id']) ?>">
                                <input
                                    type="text"
                                    name="title"
                                    value="<?= h($group['title']) ?>"
                                    style="width:180px"
                                >
                                <button class="btn btn-sm">変更</button>
                            </form>

                            <form
                                method="post"
                                id="group_delete_<?= h($group['id']) ?>"
                            >
                                <input type="hidden" name="action" value="delete_group">
                                <input type="hidden" name="surveyId" value="<?= h($survey['id']) ?>">
                                <input type="hidden" name="groupId" value="<?= h($group['id']) ?>">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger"
                                    onclick="confirmSubmit(
                                        'group_delete_<?= h($group['id']) ?>',
                                        'グループ削除',
                                        '<p>「<?= h($group['title']) ?>」を削除します。</p><p>所属する質問も削除されます。</p>'
                                    )"
                                >削除</button>
                            </form>
                        </div>
                    </div>

                    <?php foreach ($questionsByGroup[$group['id']] ?? [] as $q): ?>
                        <div class="question">
                            <div class="question-head">
                                <div class="q-number">
                                    <?= h($q['number'] ?? $q['order']) ?>
                                </div>
                                <div class="q-body">
                                    <div class="q-text">
                                        <?= h($q['text']) ?>
                                        <?php if ($q['required']): ?>
                                            <span style="color:#dc2626">＊</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="muted"><?= h(typeLabel($q['type'])) ?></span>
                                </div>
                            </div>

                            <form method="post" class="form-grid" style="margin-bottom:10px">
                                <input type="hidden" name="action" value="update_question">
                                <input type="hidden" name="surveyId" value="<?= h($survey['id']) ?>">
                                <input type="hidden" name="questionId" value="<?= h($q['id']) ?>">

                                <div class="field full">
                                    <label>質問文</label>
                                    <input type="text" name="text" value="<?= h($q['text']) ?>">
                                </div>

                                <div class="field">
                                    <label>回答形式</label>
                                    <select name="type">
                                        <option value="single" <?= $q['type'] === 'single' ? 'selected' : '' ?>>単一選択</option>
                                        <option value="multiple" <?= $q['type'] === 'multiple' ? 'selected' : '' ?>>複数選択</option>
                                        <option value="text" <?= $q['type'] === 'text' ? 'selected' : '' ?>>自由記述</option>
                                    </select>
                                </div>

                                <div class="field">
                                    <label>回答ルール</label>
                                    <label style="font-weight:400;padding-top:9px">
                                        <input
                                            type="checkbox"
                                            name="required"
                                            <?= $q['required'] ? 'checked' : '' ?>
                                        >
                                        必須
                                    </label>
                                </div>

                                <div class="actions">
                                    <button class="btn btn-sm btn-primary">質問を保存</button>
                                </div>
                            </form>

                            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                                <?php if ($q['type'] !== 'text'): ?>
                                    <div class="choice-list">
                                        <?php
                                        $choices = array_filter(
                                            $data['choices'],
                                            fn($c) => $c['questionId'] === $q['id']
                                        );
                                        usort($choices, fn($a,$b) => $a['order'] <=> $b['order']);

                                        foreach ($choices as $choice):
                                        ?>
                                            <span class="choice-chip">
                                                <?= h($choice['label']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <form method="post">
                                    <input type="hidden" name="action" value="move_question">
                                    <input type="hidden" name="surveyId" value="<?= h($survey['id']) ?>">
                                    <input type="hidden" name="questionId" value="<?= h($q['id']) ?>">
                                    <select name="groupId" onchange="this.form.submit()">
                                        <?php foreach ($groups as $targetGroup): ?>
                                            <option
                                                value="<?= h($targetGroup['id']) ?>"
                                                <?= $targetGroup['id'] === $group['id'] ? 'selected' : '' ?>
                                            >
                                                移動先：<?= h($targetGroup['title']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>

                                <form method="post" id="qdelete_<?= h($q['id']) ?>">
                                    <input type="hidden" name="action" value="delete_question">
                                    <input type="hidden" name="surveyId" value="<?= h($survey['id']) ?>">
                                    <input type="hidden" name="questionId" value="<?= h($q['id']) ?>">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-danger"
                                        onclick="confirmSubmit(
                                            'qdelete_<?= h($q['id']) ?>',
                                            '質問削除',
                                            '<p>この質問を削除しますか？</p>'
                                        )"
                                    >質問削除</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div style="padding:12px 15px">
                        <form method="post">
                            <input type="hidden" name="action" value="add_question">
                            <input type="hidden" name="surveyId" value="<?= h($survey['id']) ?>">
                            <input type="hidden" name="groupId" value="<?= h($group['id']) ?>">
                            <button class="btn btn-sm">＋ 質問追加</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<script>
function statusChanged(select) {
    const selected = select.value;
    const current = <?= json_encode($survey['status'], JSON_UNESCAPED_UNICODE) ?>;

    if (selected === current) return;

    const labels = {
        draft: '下書き',
        published: '公開中',
        stopped: '停止'
    };

    openModal(
        labels[selected] || '状態変更',
        '<p>状態を <strong>' +
        (labels[current] || current) +
        '</strong> から <strong>' +
        (labels[selected] || selected) +
        '</strong> に変更します。</p>',
        function(){
            document.getElementById('statusForm').submit();
        }
    );

    setTimeout(function(){
        select.value = current;
    }, 0);
}
</script>
<?php
    adminFooter();
}

/* -------------------------------------------------------------
 * Analysis
 * ------------------------------------------------------------- */

function renderAnalysis(array &$data, string $id): void
{
    $survey = findSurvey($data, $id);

    if (!$survey) {
        redirect('?page=list');
    }

    $answers = array_values(array_filter(
        $data['answers'],
        fn($a) => $a['surveyId'] === $id && $a['status'] === 'submitted'
    ));

    $customers = $data['customers'];
    $questions = surveyQuestions($data, $id);

    $totalCustomers = count($customers);
    $answerCount = count($answers);
    $unanswered = max(0, $totalCustomers - $answerCount);
    $rate = $totalCustomers > 0
        ? round(($answerCount / $totalCustomers) * 100, 1)
        : 0;

    $selectedQuestions = $_GET['questions'] ?? [];

    if (!is_array($selectedQuestions) || !$selectedQuestions) {
        $selectedQuestions = array_map(fn($q) => $q['id'], $questions);
    }

    adminHeader('回答集計・分析', 'list');
?>
<main class="container">
    <div class="page-head">
        <div>
            <h1>回答集計・分析</h1>
            <p>対象アンケート：<strong><?= h($survey['title']) ?></strong></p>
        </div>
        <a class="btn" href="?page=list">一覧へ戻る</a>
    </div>

    <div class="card">
        <div class="grid-3">
            <div class="stat">
                <div class="stat-label">回答数</div>
                <div class="stat-value"><?= $answerCount ?></div>
            </div>
            <div class="stat">
                <div class="stat-label">未回答数</div>
                <div class="stat-value"><?= $unanswered ?></div>
            </div>
            <div class="stat">
                <div class="stat-label">回答率</div>
                <div class="stat-value"><?= h($rate) ?>%</div>
            </div>
        </div>
    </div>

    <?php if ($answerCount === 0): ?>
        <div class="alert alert-info">
            回答がまだありません。回答が登録されると、設問別の集計結果を表示します。
        </div>
    <?php else: ?>

        <div class="card">
            <div class="section-title">
                <h2>集計する設問</h2>
                <div class="actions">
                    <button class="btn btn-sm" type="button" onclick="toggleQuestions(true)">すべて選択</button>
                    <button class="btn btn-sm" type="button" onclick="toggleQuestions(false)">すべて解除</button>
                </div>
            </div>

            <form method="get">
                <input type="hidden" name="page" value="analysis">
                <input type="hidden" name="id" value="<?= h($id) ?>">

                <div class="checkbox-grid">
                    <?php foreach ($questions as $q): ?>
                        <label class="checkbox-item">
                            <input
                                type="checkbox"
                                name="questions[]"
                                value="<?= h($q['id']) ?>"
                                <?= in_array($q['id'], $selectedQuestions, true) ? 'checked' : '' ?>
                            >
                            <?= h($q['number'] ?? $q['order']) ?>.
                            <?= h($q['text']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:12px">
                    <button class="btn btn-primary">集計を更新</button>
                </div>
            </form>
        </div>

        <?php foreach ($questions as $q): ?>
            <?php if (!in_array($q['id'], $selectedQuestions, true)) continue; ?>

            <div class="card">
                <div class="section-title">
                    <h2>
                        <?= h($q['number'] ?? $q['order']) ?>.
                        <?= h($q['text']) ?>
                    </h2>
                    <span class="badge badge-draft"><?= h(typeLabel($q['type'])) ?></span>
                </div>

                <?php if ($q['type'] === 'text'): ?>

                    <?php
                    $textAnswers = [];
                    foreach ($answers as $answer) {
                        if (isset($answer['answers'][$q['id']])) {
                            $customer = null;

                            foreach ($customers as $c) {
                                if ($c['id'] === $answer['customerId']) {
                                    $customer = $c;
                                    break;
                                }
                            }

                            $textAnswers[] = [
                                'value' => $answer['answers'][$q['id']],
                                'customer' => $customer,
                                'answer' => $answer,
                            ];
                        }
                    }
                    ?>

                    <?php if (!$textAnswers): ?>
                        <div class="empty">この設問への回答はありません。</div>
                    <?php else: ?>
                        <?php foreach ($textAnswers as $ta): ?>
                            <div class="answer-detail">
                                <div class="answer-detail-head">
                                    <?= h($ta['customer']['name'] ?? $ta['answer']['respondentInfo']['name'] ?? '未登録回答者') ?>
                                    <span class="muted">
                                        <?= h($ta['answer']['submittedAt']) ?>
                                    </span>
                                </div>
                                <div class="answer-detail-body">
                                    <?= nl2br(h((string)$ta['value'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                <?php else: ?>

                    <?php
                    $choices = array_values(array_filter(
                        $data['choices'],
                        fn($c) => $c['questionId'] === $q['id']
                    ));

                    usort($choices, fn($a,$b) => $a['order'] <=> $b['order']);

                    $counts = [];

                    foreach ($choices as $choice) {
                        $counts[$choice['label']] = 0;
                    }

                    foreach ($answers as $answer) {
                        $value = $answer['answers'][$q['id']] ?? null;

                        if (is_array($value)) {
                            foreach ($value as $v) {
                                $counts[$v] = ($counts[$v] ?? 0) + 1;
                            }
                        } elseif ($value !== null && $value !== '') {
                            $counts[$value] = ($counts[$value] ?? 0) + 1;
                        }
                    }

                    $denominator = $answerCount;
                    ?>

                    <div class="chart">
                        <?php foreach ($counts as $label => $count): ?>
                            <?php $percentage = $denominator > 0 ? round(($count / $denominator) * 100, 1) : 0; ?>
                            <div class="bar-row">
                                <div><?= h($label) ?></div>
                                <div class="bar-bg">
                                    <div
                                        class="bar-fill"
                                        style="width:<?= min(100, $percentage) ?>%"
                                    ></div>
                                </div>
                                <div><?= $count ?>件 / <?= $percentage ?>%</div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="card">
            <div class="section-title">
                <h2>個別回答</h2>
            </div>

            <?php foreach ($answers as $answer): ?>
                <?php
                $customer = null;

                foreach ($customers as $c) {
                    if ($c['id'] === $answer['customerId']) {
                        $customer = $c;
                        break;
                    }
                }
                ?>
                <details class="answer-detail">
                    <summary class="answer-detail-head">
                        <?= h($customer['name'] ?? '未登録回答者') ?>
                        ／ <?= h($answer['submittedAt']) ?>
                    </summary>

                    <div class="answer-detail-body">
                        <?php foreach ($questions as $q): ?>
                            <?php if (!isset($answer['answers'][$q['id']])) continue; ?>
                            <p>
                                <strong>
                                    <?= h($q['number'] ?? $q['order']) ?>.
                                    <?= h($q['text']) ?>
                                </strong><br>
                                <?php
                                $v = $answer['answers'][$q['id']];
                                echo nl2br(h(is_array($v) ? implode('、', $v) : $v));
                                ?>
                            </p>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <div class="actions">
                <button
                    class="btn btn-primary"
                    type="button"
                    onclick="alert('CSV出力を完了しました。（モック）')"
                >CSV出力</button>

                <button
                    class="btn"
                    type="button"
                    onclick="alert('PDF出力を完了しました。（モック）')"
                >PDF出力</button>
            </div>
        </div>

    <?php endif; ?>
</main>

<script>
function toggleQuestions(value){
    document.querySelectorAll('input[name="questions[]"]').forEach(function(el){
        el.checked = value;
    });
}
</script>
<?php
    adminFooter();
}

/* -------------------------------------------------------------
 * Send
 * ------------------------------------------------------------- */

function renderSend(array &$data, string $id): void
{
    $survey = findSurvey($data, $id);

    if (!$survey) {
        redirect('?page=list');
    }

    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $customerStatus = (string)($_GET['customerStatus'] ?? '');
    $tab = (string)($_GET['tab'] ?? 'customers');

    $customers = $data['customers'];

    if ($keyword !== '') {
        $customers = array_filter($customers, function($c) use ($keyword) {
            return mb_stripos($c['organizationName'], $keyword) !== false ||
                   mb_stripos($c['name'], $keyword) !== false ||
                   mb_stripos($c['email'], $keyword) !== false;
        });
    }

    if ($customerStatus !== '') {
        $customers = array_filter(
            $customers,
            fn($c) => $c['kintoneStatus'] === $customerStatus
        );
    }

    $histories = array_values(array_filter(
        $data['sendHistories'],
        fn($h) => $h['surveyId'] === $id
    ));

    usort($histories, fn($a,$b) => strcmp($b['sentAt'], $a['sentAt']));

    adminHeader('顧客選択・メール送信', 'list');
?>
<main class="container">
    <div class="page-head">
        <div>
            <h1>顧客選択・メール送信</h1>
            <p>対象アンケート：<strong><?= h($survey['title']) ?></strong></p>
        </div>
        <a class="btn" href="?page=list">一覧へ戻る</a>
    </div>

    <?php if (isset($_GET['sent'])): ?>
        <div class="alert alert-success">
            送信処理を完了しました。
            成功 <?= h($_GET['success'] ?? 0) ?> 件、
            失敗 <?= h($_GET['failure'] ?? 0) ?> 件です。
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="tabs">
            <?php
            $tabs = [
                'customers' => '顧客選択',
                'content' => '送信内容',
                'result' => '送信結果',
                'history' => '送信履歴',
            ];
            foreach ($tabs as $key => $label):
            ?>
                <a
                    class="<?= $tab === $key ? 'active' : '' ?>"
                    href="?page=send&id=<?= urlencode($id) ?>&tab=<?= urlencode($key) ?>"
                ><?= h($label) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ($tab === 'customers'): ?>

            <form method="get" style="margin-bottom:15px">
                <input type="hidden" name="page" value="send">
                <input type="hidden" name="id" value="<?= h($id) ?>">
                <input type="hidden" name="tab" value="customers">

                <div class="toolbar">
                    <div class="field">
                        <label>顧客検索</label>
                        <input
                            type="text"
                            name="keyword"
                            value="<?= h($keyword) ?>"
                            placeholder="会社名・氏名・メール"
                        >
                    </div>

                    <div class="field">
                        <label>kintone登録状態</label>
                        <select name="customerStatus">
                            <option value="">すべて</option>
                            <option value="registered" <?= $customerStatus === 'registered' ? 'selected' : '' ?>>登録済み</option>
                            <option value="unregistered" <?= $customerStatus === 'unregistered' ? 'selected' : '' ?>>未登録</option>
                        </select>
                    </div>

                    <button class="btn btn-primary">絞り込み</button>
                </div>
            </form>

            <form method="post" id="sendForm">
                <input type="hidden" name="action" value="send_mail">
                <input type="hidden" name="surveyId" value="<?= h($id) ?>">

                <div class="table-wrap">
                    <table style="min-width:850px">
                        <thead>
                        <tr>
                            <th>選択</th>
                            <th>顧客</th>
                            <th>氏名</th>
                            <th>メール</th>
                            <th>部署</th>
                            <th>回答状況</th>
                            <th>最終送信</th>
                            <th>送信回数</th>
                            <th>kintone</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($customers as $customer): ?>
                            <?php
                            $sentCount = 0;
                            $lastSent = '-';

                            foreach ($histories as $history) {
                                foreach ($history['messages'] ?? [] as $message) {
                                    if (($message['customerId'] ?? '') === $customer['id']) {
                                        $sentCount++;
                                        $lastSent = $history['sentAt'];
                                    }
                                }
                            }

                            $answered = false;
                            foreach ($data['answers'] as $answer) {
                                if (
                                    $answer['surveyId'] === $id &&
                                    $answer['customerId'] === $customer['id'] &&
                                    $answer['status'] === 'submitted'
                                ) {
                                    $answered = true;
                                    break;
                                }
                            }

                            $responseStatus = $answered
                                ? '回答済み'
                                : ($sentCount > 0 ? '送信済み／未回答' : '未送信');
                            ?>
                            <tr>
                                <td>
                                    <input
                                        type="checkbox"
                                        name="customers[]"
                                        value="<?= h($customer['id']) ?>"
                                    >
                                </td>
                                <td><?= h($customer['organizationName']) ?></td>
                                <td><?= h($customer['name']) ?></td>
                                <td><?= h($customer['email']) ?></td>
                                <td><?= h($customer['department']) ?></td>
                                <td><?= h($responseStatus) ?></td>
                                <td><?= h($lastSent) ?></td>
                                <td><?= $sentCount ?></td>
                                <td>
                                    <?php if ($customer['kintoneStatus'] === 'registered'): ?>
                                        <span class="badge badge-published">登録済み</span>
                                    <?php else: ?>
                                        <span class="badge badge-stopped">未登録</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:20px">
                    <a class="btn btn-primary" href="?page=send&id=<?= urlencode($id) ?>&tab=content">
                        選択した顧客の送信内容を編集
                    </a>
                </div>
            </form>

        <?php elseif ($tab === 'content'): ?>

            <form method="post" id="contentSendForm">
                <input type="hidden" name="action" value="send_mail">
                <input type="hidden" name="surveyId" value="<?= h($id) ?>">

                <div class="form-grid">
                    <div class="field full">
                        <label>メール件名</label>
                        <input
                            type="text"
                            name="subject"
                            value="【アンケートのお願い】<?= h($survey['title']) ?>"
                        >
                    </div>

                    <div class="field full">
                        <label>メール本文</label>
                        <textarea name="body">{{customerName}} 様

いつもお世話になっております。

以下のアンケートへのご回答をお願いいたします。

アンケート：
{{surveyUrl}}

ご協力のほどよろしくお願いいたします。</textarea>
                    </div>
                </div>

                <div class="card" style="background:#f8fafc">
                    <strong>動的変数</strong>
                    <p class="muted">
                        <code>{{customerName}}</code>：顧客名<br>
                        <code>{{surveyUrl}}</code>：個別アンケートURL
                    </p>
                </div>

                <div class="card">
                    <h3>プレビュー</h3>
                    <div class="mail-preview">
                        山田 太郎 様

いつもお世話になっております。

以下のアンケートへのご回答をお願いいたします。

アンケート：
https://example.test/survey.php?id=<?= h($id) ?>&customer=customer_001

ご協力のほどよろしくお願いいたします。
                    </div>
                </div>

                <div class="actions">
                    <button
                        class="btn btn-success"
                        type="button"
                        onclick="confirmSubmit(
                            'contentSendForm',
                            'メール一括送信',
                            '<p>選択した顧客へメールを送信します。</p><p>送信済み顧客が含まれる場合は再送扱いになります。</p><p><strong>モックのため実メールは送信されません。</strong></p>'
                        )"
                    >一括送信</button>

                    <a class="btn" href="?page=send&id=<?= urlencode($id) ?>&tab=customers">
                        顧客選択へ戻る
                    </a>
                </div>
            </form>

        <?php elseif ($tab === 'result'): ?>

            <?php
            $latest = $histories[0] ?? null;
            ?>

            <?php if (!$latest): ?>
                <div class="empty">まだ送信結果はありません。</div>
            <?php else: ?>
                <div class="grid-3">
                    <div class="stat">
                        <div class="stat-label">対象件数</div>
                        <div class="stat-value"><?= h($latest['count']) ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">成功件数</div>
                        <div class="stat-value result-success"><?= h($latest['success'] ?? 0) ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">失敗件数</div>
                        <div class="stat-value result-failure"><?= h($latest['failure'] ?? 0) ?></div>
                    </div>
                </div>

                <div class="kv" style="margin-top:20px">
                    <div>送信日時</div>
                    <div><?= h($latest['sentAt']) ?></div>
                    <div>件名</div>
                    <div><?= h($latest['subject']) ?></div>
                    <div>送信種別</div>
                    <div><?= h($latest['sendType']) ?></div>
                    <div>担当者</div>
                    <div><?= h($latest['operator']) ?></div>
                </div>

                <h3 style="margin-top:25px">送信結果詳細</h3>

                <?php foreach ($latest['messages'] as $message): ?>
                    <div class="answer-detail">
                        <div class="answer-detail-head">
                            <?= h($message['customerName']) ?>
                            ／
                            <span class="<?= $message['status'] === 'success' ? 'result-success' : 'result-failure' ?>">
                                <?= h($message['message']) ?>
                            </span>
                        </div>
                        <div class="answer-detail-body">
                            <strong>個別URL：</strong>
                            <?= h($message['url']) ?>
                            <hr>
                            <?= nl2br(h($message['body'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php elseif ($tab === 'history'): ?>

            <?php if (!$histories): ?>
                <div class="empty">送信履歴はありません。</div>
            <?php else: ?>

                <?php foreach ($histories as $history): ?>
                    <details class="answer-detail">
                        <summary class="answer-detail-head">
                            <?= h($history['sentAt']) ?>
                            ／
                            <?= h($history['sendType']) ?>
                            ／
                            <?= h($history['count']) ?>件
                        </summary>

                        <div class="answer-detail-body">
                            <div class="kv">
                                <div>件名</div>
                                <div><?= h($history['subject']) ?></div>
                                <div>担当者</div>
                                <div><?= h($history['operator']) ?></div>
                                <div>成功</div>
                                <div><?= h($history['success'] ?? 0) ?>件</div>
                                <div>失敗</div>
                                <div><?= h($history['failure'] ?? 0) ?>件</div>
                            </div>

                            <h4>差し込み後本文・個別URL</h4>

                            <?php foreach ($history['messages'] as $message): ?>
                                <div class="mail-preview" style="margin-bottom:8px">
<strong><?= h($message['customerName']) ?></strong>

個別URL：
<?= h($message['url']) ?>

本文：
<?= h($message['body']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>

            <?php endif; ?>

        <?php endif; ?>
    </div>
</main>
<?php
    adminFooter();
}

/* -------------------------------------------------------------
 * kintone
 * ------------------------------------------------------------- */

function renderKintone(array &$data): void
{
    $s = $data['settings']['kintone'] ?? [];

    adminHeader('kintone連携設定', 'kintone');
?>
<main class="container">
    <div class="page-head">
        <div>
            <h1>kintone連携設定</h1>
            <p>顧客情報連携のモック設定です。実際のkintone APIには接続しません。</p>
        </div>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success">設定を保存しました。</div>
    <?php endif; ?>

    <?php if (isset($_GET['tested'])): ?>
        <div class="alert alert-success">接続テスト：成功（モック）</div>
    <?php endif; ?>

    <?php if (isset($_GET['fields'])): ?>
        <div class="alert alert-success">項目一覧を取得しました。（モック）</div>
    <?php endif; ?>

    <?php if (isset($_GET['sync'])): ?>
        <div class="alert alert-success">顧客情報同期を実行しました。（モック）</div>
    <?php endif; ?>

    <div class="card">
        <form method="post">
            <input type="hidden" name="action" value="save_kintone">

            <div class="form-grid">
                <div class="field">
                    <label>サブドメイン</label>
                    <input type="text" name="subdomain" value="<?= h($s['subdomain'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>顧客管理アプリID</label>
                    <input type="number" name="appId" value="<?= h($s['appId'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>ログイン名</label>
                    <input type="text" name="loginName" value="<?= h($s['loginName'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>パスワード</label>
                    <input type="password" name="password" value="<?= h($s['password'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>SSL検証</label>
                    <label style="font-weight:400">
                        <input
                            type="checkbox"
                            name="sslVerify"
                            <?= !empty($s['sslVerify']) ? 'checked' : '' ?>
                        >
                        SSL証明書を検証する
                    </label>
                </div>
            </div>

            <div style="margin-top:18px">
                <button class="btn btn-primary">設定を保存</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="section-title">
            <h2>接続操作</h2>
        </div>

        <div class="actions">
            <form method="post">
                <input type="hidden" name="action" value="test_kintone">
                <button class="btn">接続テスト</button>
            </form>

            <form method="post">
                <input type="hidden" name="action" value="fetch_kintone_fields">
                <button class="btn">項目一覧を取得</button>
            </form>

            <form method="post">
                <input type="hidden" name="action" value="sync_kintone">
                <button
                    class="btn btn-primary"
                    type="submit"
                >顧客情報同期</button>
            </form>
        </div>

        <p class="muted" style="margin-top:12px">
            接続テスト、設定保存、項目一覧取得、顧客情報同期は独立した操作です。
        </p>
    </div>

    <div class="card">
        <div class="section-title">
            <h2>フィールドマッピング</h2>
        </div>

        <div class="form-grid">
            <div class="field">
                <label>会社名</label>
                <select name="mapping_company">
                    <option>会社名（company_name）</option>
                    <option>企業名称（company）</option>
                </select>
            </div>

            <div class="field">
                <label>氏名</label>
                <select name="mapping_name">
                    <option>氏名（name）</option>
                    <option>担当者名（person_name）</option>
                </select>
            </div>

            <div class="field">
                <label>メールアドレス</label>
                <select name="mapping_email">
                    <option>メールアドレス（email）</option>
                    <option>連絡先メール（contact_email）</option>
                </select>
            </div>

            <div class="field">
                <label>部署</label>
                <select name="mapping_department">
                    <option>部署（department）</option>
                    <option>所属部署（section）</option>
                </select>
            </div>

            <div class="field full">
                <label>住所（複数フィールド指定可）</label>
                <div class="checkbox-grid">
                    <label class="checkbox-item">
                        <input type="checkbox" name="addressFields[]" value="postal">
                        郵便番号
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" name="addressFields[]" value="prefecture">
                        都道府県
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" name="addressFields[]" value="city">
                        市区町村
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" name="addressFields[]" value="address">
                        町名・番地
                    </label>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">未登録回答者</h2>
        <p>
            回答者と既存顧客が一致しない場合は、
            <span class="badge badge-stopped">未登録</span>
            として扱います。
        </p>
    </div>
</main>
<?php
    adminFooter();
}

/* -------------------------------------------------------------
 * SMTP
 * ------------------------------------------------------------- */

function renderSmtp(array &$data): void
{
    $s = $data['settings']['smtp'] ?? [];

    adminHeader('メールサーバ設定', 'smtp');
?>
<main class="container">
    <div class="page-head">
        <div>
            <h1>メールサーバ設定</h1>
            <p>SMTP接続設定です。実際のメール送信は行いません。</p>
        </div>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success">SMTP設定を保存しました。</div>
    <?php endif; ?>

    <?php if (isset($_GET['tested'])): ?>
        <div class="alert alert-success">テストメール送信：成功（モック）</div>
    <?php endif; ?>

    <div class="card">
        <div class="section-title">
            <h2>接続状態</h2>
            <?php
            $status = $s['status'] ?? '未設定';
            $class = $status === '成功'
                ? 'badge-published'
                : ($status === '失敗' ? 'badge-ended' : 'badge-draft');
            ?>
            <span class="badge <?= h($class) ?>">
                <?= h($status) ?>
            </span>
        </div>

        <form method="post">
            <input type="hidden" name="action" value="save_smtp">

            <div class="form-grid">
                <div class="field">
                    <label>SMTPサーバ</label>
                    <input type="text" name="server" value="<?= h($s['server'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>SMTPポート</label>
                    <input type="number" name="port" value="<?= h($s['port'] ?? 587) ?>">
                </div>

                <div class="field">
                    <label>暗号化方式</label>
                    <select name="encryption">
                        <?php foreach (['SSL','TLS','なし'] as $enc): ?>
                            <option
                                value="<?= h($enc) ?>"
                                <?= ($s['encryption'] ?? 'TLS') === $enc ? 'selected' : '' ?>
                            >
                                <?= h($enc) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>SMTP認証</label>
                    <label style="font-weight:400">
                        <input
                            type="checkbox"
                            name="auth"
                            <?= !empty($s['auth']) ? 'checked' : '' ?>
                        >
                        SMTP認証を使用する
                    </label>
                </div>

                <div class="field">
                    <label>SMTPユーザー名</label>
                    <input type="text" name="username" value="<?= h($s['username'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>SMTPパスワード</label>
                    <input type="password" name="password" value="<?= h($s['password'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>送信元メールアドレス</label>
                    <input type="email" name="from" value="<?= h($s['from'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>送信者名</label>
                    <input type="text" name="senderName" value="<?= h($s['senderName'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>返信先メールアドレス</label>
                    <input type="email" name="replyTo" value="<?= h($s['replyTo'] ?? '') ?>">
                </div>
            </div>

            <div style="margin-top:18px">
                <button class="btn btn-primary">設定を保存</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2 class="section-title">テストメール</h2>

        <p class="muted">
            実際のメール送信は行わず、モック上で成功状態を表示します。
        </p>

        <form method="post">
            <input type="hidden" name="action" value="test_smtp">
            <button class="btn btn-primary">テストメール送信</button>
        </form>
    </div>
</main>
<?php
    adminFooter();
}

/* -------------------------------------------------------------
 * Respondent
 * ------------------------------------------------------------- */

function respondentSurvey(array &$data, string $surveyId): ?array
{
    return findSurvey($data, $surveyId);
}

function renderRespondent(array &$data, bool $confirm = false): void
{
    $surveyId = (string)($_GET['id'] ?? $_POST['surveyId'] ?? '');
    $customerId = (string)($_GET['customer'] ?? $_POST['customerId'] ?? '');

    $survey = respondentSurvey($data, $surveyId);

    if (!$survey) {
        respondentSimplePage('アンケートが見つかりません。');
        return;
    }

    if ($survey['status'] !== 'published') {
        respondentSimplePage(
            'このアンケートは現在回答できません。',
            '公開期間または公開状態をご確認ください。'
        );
        return;
    }

    $customer = null;

    foreach ($data['customers'] as $c) {
        if ($c['id'] === $customerId) {
            $customer = $c;
            break;
        }
    }

    $sessionKey = 'answers_' . $surveyId . '_' . ($customerId ?: 'public');

    if (!isset($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = [];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$confirm) {
        foreach ($_POST['answers'] ?? [] as $questionId => $value) {
            $_SESSION[$sessionKey][$questionId] = $value;
        }

        header(
            'Location: ?page=confirm&id=' .
            urlencode($surveyId) .
            '&customer=' .
            urlencode($customerId)
        );
        exit;
    }

    $questions = surveyQuestions($data, $surveyId);

    $values = $_SESSION[$sessionKey];

    respondentHeader($survey['title']);

    if ($confirm):
?>
<main class="respondent-container">
    <div class="respondent-progress">
        <span>回答確認</span>
        <span>最終確認</span>
    </div>

    <section class="respondent-card">
        <h1>回答内容の確認</h1>
        <p class="respondent-description">
            回答内容をご確認ください。
        </p>

        <?php foreach ($questions as $q): ?>
            <?php
            $value = $values[$q['id']] ?? '';
            if ($value === '') continue;
            ?>
            <div class="confirm-item">
                <div class="confirm-question">
                    <?= h($q['number'] ?? $q['order']) ?>.
                    <?= h($q['text']) ?>
                </div>
                <div class="confirm-answer">
                    <?= nl2br(h(is_array($value) ? implode('、', $value) : $value)) ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="respondent-actions">
            <a
                class="r-btn r-btn-secondary"
                href="?page=respond&id=<?= urlencode($surveyId) ?>&customer=<?= urlencode($customerId) ?>"
            >戻る・修正</a>

            <form method="post" action="?page=complete">
                <input type="hidden" name="surveyId" value="<?= h($surveyId) ?>">
                <input type="hidden" name="customerId" value="<?= h($customerId) ?>">
                <button
                    class="r-btn r-btn-primary"
                    type="button"
                    onclick="confirmAnswerSubmit(this.form)"
                >送信する</button>
            </form>
        </div>
    </section>
</main>

<script>
function confirmAnswerSubmit(form) {
    if (confirm('回答を送信します。送信後は回答済みとして扱われます。よろしいですか？')) {
        form.submit();
    }
}
</script>
<?php
    else:
?>
<main class="respondent-container">
    <div class="respondent-progress">
        <span class="active">回答</span>
        <span>確認</span>
        <span>完了</span>
    </div>

    <section class="respondent-card">
        <h1><?= h($survey['title']) ?></h1>

        <?php if ($survey['description'] !== ''): ?>
            <p class="respondent-description">
                <?= nl2br(h($survey['description'])) ?>
            </p>
        <?php endif; ?>

        <?php if ($customer): ?>
            <div class="respondent-notice">
                <?= h($customer['name']) ?> 様
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="surveyId" value="<?= h($surveyId) ?>">
            <input type="hidden" name="customerId" value="<?= h($customerId) ?>">

            <?php
            $currentGroup = '';

            foreach ($questions as $q):
            ?>
                <?php if ($currentGroup !== $q['_groupTitle']): ?>
                    <?php $currentGroup = $q['_groupTitle']; ?>
                    <h2 class="respondent-group-title">
                        <?= h($currentGroup) ?>
                    </h2>
                <?php endif; ?>

                <div
                    class="respondent-question"
                    data-question-id="<?= h($q['id']) ?>"
                >
                    <label class="respondent-question-title">
                        <?= h($q['number'] ?? $q['order']) ?>.
                        <?= h($q['text']) ?>
                        <?php if ($q['required']): ?>
                            <span class="required">必須</span>
                        <?php endif; ?>
                    </label>

                    <?php
                    $choices = array_values(array_filter(
                        $data['choices'],
                        fn($c) => $c['questionId'] === $q['id']
                    ));
                    usort($choices, fn($a,$b) => $a['order'] <=> $b['order']);

                    $oldValue = $values[$q['id']] ?? '';
                    ?>

                    <?php if ($q['type'] === 'single'): ?>

                        <div class="respondent-options">
                            <?php foreach ($choices as $choice): ?>
                                <label class="respondent-option">
                                    <input
                                        type="radio"
                                        name="answers[<?= h($q['id']) ?>]"
                                        value="<?= h($choice['label']) ?>"
                                        <?= $oldValue === $choice['label'] ? 'checked' : '' ?>
                                    >
                                    <span><?= h($choice['label']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                    <?php elseif ($q['type'] === 'multiple'): ?>

                        <?php
                        $oldArray = is_array($oldValue) ? $oldValue : [];
                        ?>
                        <div class="respondent-options">
                            <?php foreach ($choices as $choice): ?>
                                <label class="respondent-option">
                                    <input
                                        type="checkbox"
                                        name="answers[<?= h($q['id']) ?>][]"
                                        value="<?= h($choice['label']) ?>"
                                        <?= in_array($choice['label'], $oldArray, true) ? 'checked' : '' ?>
                                    >
                                    <span><?= h($choice['label']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                    <?php else: ?>

                        <textarea
                            name="answers[<?= h($q['id']) ?>]"
                            rows="5"
                            placeholder="回答を入力してください"
                        ><?= h(is_array($oldValue) ? implode('、', $oldValue) : $oldValue) ?></textarea>

                    <?php endif; ?>

                    <?php if ($q['required']): ?>
                        <div
                            class="respondent-error"
                            style="display:none"
                        >この質問は必須です。</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="respondent-actions">
                <a
                    class="r-btn r-btn-secondary"
                    href="?page=respond&id=<?= urlencode($surveyId) ?>&customer=<?= urlencode($customerId) ?>"
                >戻る</a>

                <button
                    class="r-btn r-btn-primary"
                    type="submit"
                    onclick="return validateRespondentForm(this.form)"
                >回答確認</button>
            </div>
        </form>
    </section>
</main>

<script>
function validateRespondentForm(form) {
    let valid = true;

    document.querySelectorAll('.respondent-question').forEach(function(q){
        const required = q.querySelector('.required');
        if (!required) return;

        let answered = false;

        q.querySelectorAll('input[type=radio]').forEach(function(el){
            if (el.checked) answered = true;
        });

        q.querySelectorAll('input[type=checkbox]').forEach(function(el){
            if (el.checked) answered = true;
        });

        const textarea = q.querySelector('textarea');
        if (textarea && textarea.value.trim() !== '') {
            answered = true;
        }

        const error = q.querySelector('.respondent-error');

        if (!answered) {
            valid = false;
            if (error) error.style.display = 'block';
        } else {
            if (error) error.style.display = 'none';
        }
    });

    if (!valid) {
        window.scrollTo({
            top: document.querySelector('.respondent-error[style*="block"]')?.offsetTop - 30 || 0,
            behavior: 'smooth'
        });
    }

    return valid;
}
</script>
<?php
    endif;

    respondentFooter();
}

function renderRespondentComplete(): void
{
    $surveyId = (string)($_POST['surveyId'] ?? '');
    $customerId = (string)($_POST['customerId'] ?? '');

    $data = loadData();
    $survey = findSurvey($data, $surveyId);

    if (!$survey) {
        respondentSimplePage('アンケートが見つかりません。');
        return;
    }

    $sessionKey = 'answers_' . $surveyId . '_' . ($customerId ?: 'public');
    $answers = $_SESSION[$sessionKey] ?? [];

    $alreadySubmitted = false;

    foreach ($data['answers'] as $answer) {
        if (
            $answer['surveyId'] === $surveyId &&
            $answer['customerId'] === $customerId
        ) {
            $alreadySubmitted = true;
            break;
        }
    }

    if (!$alreadySubmitted || $survey['allowResubmission']) {
        $respondentInfo = [
            'name' => '',
            'email' => '',
        ];

        foreach ($data['customers'] as $customer) {
            if ($customer['id'] === $customerId) {
                $respondentInfo = [
                    'name' => $customer['name'],
                    'email' => $customer['email'],
                ];
                break;
            }
        }

        $data['answers'][] = [
            'id' => uid('answer'),
            'surveyId' => $surveyId,
            'customerId' => $customerId,
            'respondentInfo' => $respondentInfo,
            'answers' => $answers,
            'submittedAt' => now(),
            'status' => 'submitted',
        ];

        saveData($data);
    }

    unset($_SESSION[$sessionKey]);

    respondentHeader($survey['title']);
?>
<main class="respondent-container">
    <div class="respondent-progress">
        <span>回答</span>
        <span>確認</span>
        <span class="active">完了</span>
    </div>

    <section class="respondent-card complete-card">
        <div class="complete-icon">✓</div>
        <h1>回答ありがとうございました</h1>
        <p>
            アンケートの回答を受け付けました。
        </p>
        <p class="muted">
            この画面を閉じて終了してください。
        </p>
    </section>
</main>
<?php
    respondentFooter();
}

function respondentHeader(string $title): void
{
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?></title>
<style>
:root{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --bg:#f4f7fb;
    --surface:#fff;
    --text:#1f2937;
    --muted:#64748b;
    --border:#dbe3ee;
    --danger:#dc2626;
}
*{box-sizing:border-box}
body{
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Kaku Gothic ProN","Yu Gothic",Meiryo,sans-serif;
    line-height:1.7;
}
.respondent-header{
    background:#fff;
    border-bottom:1px solid var(--border);
    padding:18px 20px;
}
.respondent-header-inner{
    width:min(760px,100%);
    margin:auto;
}
.respondent-brand{
    font-size:13px;
    color:var(--muted);
}
.respondent-title{
    font-size:17px;
    font-weight:700;
    margin-top:3px;
}
.respondent-container{
    width:min(760px,calc(100% - 24px));
    margin:25px auto 60px;
}
.respondent-progress{
    display:flex;
    justify-content:center;
    gap:0;
    margin-bottom:20px;
}
.respondent-progress span{
    color:#94a3b8;
    background:#e2e8f0;
    padding:7px 18px;
    font-size:13px;
}
.respondent-progress span:first-child{border-radius:20px 0 0 20px}
.respondent-progress span:last-child{border-radius:0 20px 20px 0}
.respondent-progress span.active{
    background:#dbeafe;
    color:#1d4ed8;
    font-weight:700;
}
.respondent-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:25px;
    box-shadow:0 3px 15px rgba(15,23,42,.05);
}
.respondent-card h1{
    font-size:24px;
    margin:0 0 10px;
}
.respondent-description{
    color:var(--muted);
    margin-bottom:22px;
}
.respondent-notice{
    background:#eff6ff;
    color:#1e40af;
    border-radius:8px;
    padding:11px 13px;
    margin-bottom:20px;
}
.respondent-group-title{
    font-size:18px;
    border-left:4px solid var(--primary);
    padding-left:10px;
    margin:30px 0 15px;
}
.respondent-question{
    padding:18px 0;
    border-bottom:1px solid #e5e7eb;
}
.respondent-question-title{
    display:block;
    font-size:16px;
    font-weight:700;
    margin-bottom:12px;
}
.required{
    display:inline-block;
    margin-left:7px;
    color:#b91c1c;
    font-size:11px;
    background:#fee2e2;
    padding:2px 6px;
    border-radius:4px;
    vertical-align:middle;
}
.respondent-options{
    display:flex;
    flex-direction:column;
    gap:9px;
}
.respondent-option{
    display:flex;
    align-items:center;
    gap:11px;
    border:1px solid var(--border);
    border-radius:9px;
    padding:12px 13px;
    min-height:48px;
    cursor:pointer;
    background:#fff;
}
.respondent-option:hover{
    border-color:#93c5fd;
    background:#f8fbff;
}
.respondent-option input{
    width:20px;
    height:20px;
    flex:none;
}
.respondent-question textarea{
    width:100%;
    min-height:120px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:12px;
    font:inherit;
    resize:vertical;
}
.respondent-question textarea:focus{
    outline:3px solid rgba(37,99,235,.12);
    border-color:var(--primary);
}
.respondent-error{
    color:var(--danger);
    font-size:13px;
    margin-top:7px;
}
.respondent-actions{
    display:flex;
    justify-content:space-between;
    gap:12px;
    margin-top:25px;
}
.r-btn{
    min-height:50px;
    border-radius:9px;
    padding:10px 22px;
    border:1px solid var(--border);
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-weight:700;
    font-size:15px;
    cursor:pointer;
}
.r-btn-primary{
    background:var(--primary);
    border-color:var(--primary);
    color:#fff;
}
.r-btn-primary:hover{background:var(--primary-dark)}
.r-btn-secondary{
    background:#fff;
    color:#374151;
}
.confirm-item{
    border-bottom:1px solid #e5e7eb;
    padding:15px 0;
}
.confirm-question{
    font-weight:700;
}
.confirm-answer{
    margin-top:7px;
    background:#f8fafc;
    border-radius:7px;
    padding:10px;
}
.complete-card{
    text-align:center;
    padding:50px 25px;
}
.complete-icon{
    width:70px;
    height:70px;
    margin:0 auto 20px;
    background:#dcfce7;
    color:#15803d;
    border-radius:50%;
    display:flex;
    justify-content:center;
    align-items:center;
    font-size:38px;
    font-weight:700;
}
.muted{color:var(--muted)}
@media(max-width:600px){
    .respondent-container{
        width:calc(100% - 16px);
        margin-top:12px;
    }
    .respondent-card{
        padding:17px;
        border-radius:10px;
    }
    .respondent-card h1{
        font-size:21px;
    }
    .respondent-progress span{
        padding:6px 9px;
        font-size:11px;
    }
    .respondent-actions{
        position:sticky;
        bottom:0;
        background:rgba(255,255,255,.96);
        padding:10px 0;
        margin-left:-5px;
        margin-right:-5px;
    }
    .r-btn{
        flex:1;
        min-height:52px;
    }
}
</style>
</head>
<body>
<header class="respondent-header">
    <div class="respondent-header-inner">
        <div class="respondent-brand">アンケート</div>
        <div class="respondent-title"><?= h($title) ?></div>
    </div>
</header>
<?php
}

function respondentFooter(): void
{
?>
</body>
</html>
<?php
}

function respondentSimplePage(string $title, string $description = ''): void
{
    respondentHeader($title);
?>
<main class="respondent-container">
    <section class="respondent-card complete-card">
        <h1><?= h($title) ?></h1>
        <?php if ($description): ?>
            <p><?= h($description) ?></p>
        <?php endif; ?>
    </section>
</main>
<?php
    respondentFooter();
}

/* -------------------------------------------------------------
 * Routing
 * ------------------------------------------------------------- */

switch ($page) {
    case 'list':
        renderList($data);
        break;

    case 'edit':
        renderEdit($data, $surveyId);
        break;

    case 'analysis':
        if ($surveyId === '') {
            redirect('?page=list');
        }
        renderAnalysis($data, $surveyId);
        break;

    case 'send':
        if ($surveyId === '') {
            redirect('?page=list');
        }
        renderSend($data, $surveyId);
        break;

    case 'kintone':
        renderKintone($data);
        break;

    case 'smtp':
        renderSmtp($data);
        break;

    default:
        redirect('?page=list');
}
?>
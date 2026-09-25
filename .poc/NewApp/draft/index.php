<?php
declare(strict_types=1);
namespace Jacic\Gojacic\QuestionnaireOperations;

use DateTimeImmutable;
use RuntimeException;

date_default_timezone_set('Asia/Tokyo');

const SKEY = 'jacic_gojacic_questionnaire_operations';
const DATADIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
  session_start();
}
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

if (!isset($_SESSION[SKEY]['csrf'])) {
  $_SESSION[SKEY]['csrf'] = bin2hex(random_bytes(32));
}

function h(?string $s): string {
  return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nowi(): string {
  return (new DateTimeImmutable())->format('c');
}

function nows(): string {
  return (new DateTimeImmutable())->format('Y-m-d H:i:s');
}

function rid(string $p = ''): string {
  return $p . bin2hex(random_bytes(8));
}

function jp(mixed $v): string {
  return json_encode(
    $v,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
  );
}

function pathOf(string $f): string {
  if (!is_dir(DATADIR) && !mkdir(DATADIR, 0775, true) && !is_dir(DATADIR)) {
    throw new RuntimeException('データフォルダを作成できません。');
  }
  return DATADIR . DIRECTORY_SEPARATOR . $f;
}

function writeJson(string $f, mixed $data): void {
  $p = pathOf($f);
  $fp = fopen($p, 'c+b');

  if ($fp === false) {
    throw new RuntimeException('データ保存に失敗しました。');
  }

  if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    throw new RuntimeException('データをロックできません。');
  }

  $json = jp($data);

  if (!ftruncate($fp, 0)) {
    flock($fp, LOCK_UN);
    fclose($fp);
    throw new RuntimeException('データを更新できません。');
  }

  rewind($fp);
  $ok = fwrite($fp, $json);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  if ($ok === false || $ok < strlen($json)) {
    throw new RuntimeException('データを更新できません。');
  }
}

function readJson(string $f, mixed $default): mixed {
  $p = pathOf($f);

  if (!file_exists($p)) {
    writeJson($f, $default);
    return $default;
  }

  $fp = fopen($p, 'rb');

  if ($fp === false) {
    throw new RuntimeException('データを開けません。');
  }

  if (!flock($fp, LOCK_SH)) {
    fclose($fp);
    throw new RuntimeException('データをロックできません。');
  }

  $s = stream_get_contents($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  if ($s === false || trim($s) === '') {
    throw new RuntimeException('JSONデータが空です。');
  }

  try {
    return json_decode($s, true, 512, JSON_THROW_ON_ERROR);
  } catch (\Throwable) {
    throw new RuntimeException('JSONデータが破損しています。');
  }
}

function allData(): array {
  initData();

  return [
    'surveys'   => readJson('surveys.json', []),
    'customers' => readJson('customers.json', []),
    'responses' => readJson('responses.json', []),
    'tokens'    => readJson('answer_tokens.json', []),
    'logs'      => readJson('send_logs.json', []),
    'settings'  => readJson('settings.json', []),
  ];
}

function surveyIndex(array $surveys, string $id): int {
  foreach ($surveys as $i => $s) {
    if ((string)($s['id'] ?? '') === $id) {
      return $i;
    }
  }
  return -1;
}

function questions(array $s): array {
  $r = [];

  foreach (($s['groups'] ?? []) as $g) {
    foreach (($g['questions'] ?? []) as $q) {
      $r[] = $q;
    }
  }

  return $r;
}

function qmap(array $s): array {
  $r = [];

  foreach (questions($s) as $q) {
    $r[(string)$q['id']] = $q;
  }

  return $r;
}

function qnums(array $s): array {
  $r = [];
  $g = 1;
  $n = 1;

  foreach (($s['groups'] ?? []) as $gr) {
    $n = 1;

    foreach (($gr['questions'] ?? []) as $q) {
      $r[$q['id']] =
        (($s['numberingFormat'] ?? 'group') === 'global')
          ? 'Q' . $n
          : 'Q' . $g . '-' . $n;

      $n++;
    }

    $g++;
  }

  if (($s['numberingFormat'] ?? 'group') === 'global') {
    $n = 1;
    $r = [];

    foreach (($s['groups'] ?? []) as $gr) {
      foreach (($gr['questions'] ?? []) as $q) {
        $r[$q['id']] = 'Q' . $n++;
      }
    }
  }

  return $r;
}

function initData(): void {
  if (file_exists(pathOf('surveys.json'))) {
    return;
  }

  $sv1 = [
    'id' => rid('sv_'),
    'name' => '新サービス利用満足度調査 2026',
    'description' => '新サービスをご利用いただいた皆さまから、利用状況や満足度についてお伺いします。',
    'status' => 'published',
    'startAt' => '2026-01-01T00:00:00+09:00',
    'endAt' => '2026-12-31T23:59:59+09:00',
    'numberingFormat' => 'group',
    'groups' => [
      [
        'id' => rid('g_'),
        'name' => '利用状況',
        'questions' => [
          [
            'id' => 'q_usage',
            'text' => 'サービスをどのくらいの頻度で利用していますか？',
            'type' => 'single',
            'required' => true,
            'choices' => [
              ['id' => 'c_daily', 'label' => '毎日'],
              ['id' => 'c_weekly', 'label' => '週に数回'],
              ['id' => 'c_monthly', 'label' => '月に数回'],
              ['id' => 'c_rarely', 'label' => 'ほとんど利用しない'],
            ],
            'branches' => [],
          ],
          [
            'id' => 'q_sat',
            'text' => '総合的な満足度を教えてください。',
            'type' => 'single',
            'required' => true,
            'choices' => [
              ['id' => 'c_vgood', 'label' => 'とても満足'],
              ['id' => 'c_good', 'label' => '満足'],
              ['id' => 'c_neutral', 'label' => 'どちらともいえない'],
              ['id' => 'c_bad', 'label' => '不満'],
              ['id' => 'c_vbad', 'label' => 'とても不満'],
            ],
            'branches' => [],
          ],
        ],
      ],
      [
        'id' => rid('g_'),
        'name' => 'ご意見',
        'questions' => [
          [
            'id' => 'q_comment',
            'text' => '今後改善してほしい点があれば教えてください。',
            'type' => 'text',
            'required' => false,
            'choices' => [],
            'branches' => [],
          ],
        ],
      ],
    ],
    'createdAt' => '2026-01-05T10:00:00+09:00',
    'updatedAt' => nowi(),
  ];

  $sv2 = [
    'id' => rid('sv_'),
    'name' => 'ユーザー会参加希望アンケート',
    'description' => '次回ユーザー会への参加希望についてお聞かせください。',
    'status' => 'draft',
    'startAt' => nowi(),
    'endAt' => (new DateTimeImmutable('+1 month'))->format('c'),
    'numberingFormat' => 'global',
    'groups' => [
      [
        'id' => rid('g_'),
        'name' => '参加希望',
        'questions' => [
          [
            'id' => 'q_join',
            'text' => '次回ユーザー会に参加したいですか？',
            'type' => 'single',
            'required' => true,
            'choices' => [
              ['id' => 'c_yes', 'label' => '参加したい'],
              ['id' => 'c_no', 'label' => '参加しない'],
            ],
            'branches' => [],
          ],
          [
            'id' => 'q_reason',
            'text' => '参加したい理由を教えてください。',
            'type' => 'text',
            'required' => false,
            'choices' => [],
            'branches' => [],
          ],
        ],
      ],
    ],
    'createdAt' => nowi(),
    'updatedAt' => nowi(),
  ];

  writeJson('surveys.json', [$sv1, $sv2]);

  writeJson('customers.json', [
    [
      'id' => 'cust_001',
      'name' => '山田 太郎',
      'email' => 'taro@example.com',
      'company' => 'サンプル株式会社',
      'updatedAt' => nowi(),
    ],
    [
      'id' => 'cust_002',
      'name' => '佐藤 花子',
      'email' => 'hanako@example.com',
      'company' => 'テスト商事',
      'updatedAt' => nowi(),
    ],
    [
      'id' => 'cust_003',
      'name' => '鈴木 一郎',
      'email' => 'ichiro@example.com',
      'company' => 'デモ企業',
      'updatedAt' => nowi(),
    ],
  ]);

  writeJson('responses.json', []);
  writeJson('answer_tokens.json', []);
  writeJson('send_logs.json', []);

  writeJson('settings.json', [
    'smtp' => [
      'host' => '',
      'port' => 587,
      'secure' => 'tls',
      'username' => '',
      'password' => '',
      'fromEmail' => '',
      'fromName' => 'アンケート事務局',
    ],
    'kintone' => [
      'subdomain' => '',
      'appId' => '',
      'login' => '',
      'password' => '',
      'nameField' => '会社名',
      'emailField' => 'メールアドレス',
      'proxyHostPort' => '',
      'verifySsl' => false,
    ],
  ]);
}

function validateSurvey(array $s): array {
  $e = [];
  $name = trim((string)($s['name'] ?? ''));

  if ($name === '') {
    $e['name'] = 'アンケート名は必須です。';
  }

  try {
    if (
      new DateTimeImmutable((string)$s['endAt']) <
      new DateTimeImmutable((string)$s['startAt'])
    ) {
      $e['period'] = '公開終了日時は開始日時以降にしてください。';
    }
  } catch (\Throwable) {
    $e['period'] = '公開期間が正しくありません。';
  }

  $gids = [];
  $qids = [];

  foreach (($s['groups'] ?? []) as $gi => $g) {
    $gid = (string)($g['id'] ?? '');

    if ($gid === '' || isset($gids[$gid])) {
      $e['group' . $gi] = 'グループIDが不正です。';
    }

    $gids[$gid] = 1;

    if (trim((string)($g['name'] ?? '')) === '') {
      $e['gname' . $gi] = 'グループ名は必須です。';
    }

    foreach (($g['questions'] ?? []) as $qi => $q) {
      $qid = (string)($q['id'] ?? '');

      if ($qid === '' || isset($qids[$qid])) {
        $e['qid' . $gi . '_' . $qi] = '質問IDが不正です。';
      }

      $qids[$qid] = 1;

      if (trim((string)($q['text'] ?? '')) === '') {
        $e['qtext' . $gi . '_' . $qi] = '質問文は必須です。';
      }

      $type = (string)($q['type'] ?? '');

      if (!in_array($type, ['text', 'single', 'multiple'], true)) {
        $e['qtype' . $gi . '_' . $qi] = '回答形式が不正です。';
        continue;
      }

      $choices = is_array($q['choices'] ?? null)
        ? $q['choices']
        : [];

      if ($type === 'text' && $choices !== []) {
        $e['choices' . $gi . '_' . $qi] = '自由記述に選択肢は設定できません。';
      }

      if ($type !== 'text' && !$choices) {
        $e['choices' . $gi . '_' . $qi] = '選択肢を1つ以上設定してください。';
      }

      $cids = [];

      foreach ($choices as $ci => $c) {
        $cid = (string)($c['id'] ?? '');
        $lab = trim((string)($c['label'] ?? ''));

        if ($cid === '' || isset($cids[$cid])) {
          $e['cid' . $gi . '_' . $qi . '_' . $ci] = '選択肢IDが不正です。';
        }

        $cids[$cid] = 1;

        if ($lab === '') {
          $e['clabel' . $gi . '_' . $qi . '_' . $ci] = '選択肢の文言は必須です。';
        }
      }

      if ($type !== 'single' && !empty($q['branches'])) {
        $e['branch' . $gi . '_' . $qi] = '分岐は単一選択のみです。';
      }
    }
  }

  $qm = qmap($s);

  foreach (($s['groups'] ?? []) as $gi => $g) {
    foreach (($g['questions'] ?? []) as $qi => $q) {
      if (($q['type'] ?? '') !== 'single') {
        continue;
      }

      foreach (($q['branches'] ?? []) as $cid => $target) {
        $target = (string)$target;

        if ($target === '' || $target === 'next' || $target === 'end') {
          continue;
        }

        $tid = str_starts_with($target, 'question:')
          ? substr($target, 9)
          : $target;

        if (!isset($qm[$tid])) {
          $e['bt' . $gi . '_' . $qi] = '分岐先の質問が存在しません。';
        }
      }
    }
  }

  if (empty($s['groups'])) {
    $e['groups'] = 'グループを1つ以上設定してください。';
  }

  if (count(questions($s)) === 0) {
    $e['questions'] = '質問を1つ以上設定してください。';
  }

  if (!$e && cycle($s)) {
    $e['branches'] = '分岐に循環が存在します。';
  }

  return $e;
}

function cycle(array $s): bool {
  $qs = questions($s);
  $ix = [];

  foreach ($qs as $i => $q) {
    $ix[$q['id']] = $i;
  }

  $graph = [];

  foreach ($qs as $i => $q) {
    $targets = [];

    if (isset($qs[$i + 1])) {
      $targets[] = $qs[$i + 1]['id'];
    }

    if (($q['type'] ?? '') === 'single') {
      foreach (($q['branches'] ?? []) as $t) {
        $t = (string)$t;

        if ($t === '' || $t === 'end') {
          continue;
        }

        if ($t === 'next') {
          if (isset($qs[$i + 1])) {
            $targets[] = $qs[$i + 1]['id'];
          }
        } else {
          $tid = str_starts_with($t, 'question:')
            ? substr($t, 9)
            : $t;

          if (isset($ix[$tid])) {
            $targets[] = $tid;
          }
        }
      }
    }

    $graph[$q['id']] = array_values(array_unique($targets));
  }

  $state = [];

  $visit = function (string $id) use (&$visit, &$state, $graph): bool {
    $state[$id] = 1;

    foreach ($graph[$id] ?? [] as $next) {
      if (($state[$next] ?? 0) === 1) {
        return true;
      }

      if (($state[$next] ?? 0) === 0 && $visit($next)) {
        return true;
      }
    }

    $state[$id] = 2;
    return false;
  };

  foreach (array_keys($graph) as $id) {
    if (($state[$id] ?? 0) === 0 && $visit($id)) {
      return true;
    }
  }

  return false;
}

function reachable(array $s, array $answers): array {
  $qs = questions($s);
  $ix = [];

  foreach ($qs as $i => $q) {
    $ix[$q['id']] = $i;
  }

  $out = [];
  $i = 0;
  $seen = [];
  $guard = 0;

  while (isset($qs[$i]) && $guard++ < 10000) {
    $q = $qs[$i];

    if (isset($seen[$q['id']])) {
      break;
    }

    $seen[$q['id']] = 1;
    $out[$q['id']] = 1;

    if (($q['type'] ?? '') !== 'single') {
      $i++;
      continue;
    }

    $a = $answers[$q['id']] ?? null;
    $target = 'next';

    foreach (($q['choices'] ?? []) as $c) {
      if ((string)$c['id'] === (string)$a) {
        $target = (string)(($q['branches'] ?? [])[$c['id']] ?? 'next');
      }
    }

    if ($target === 'end') {
      break;
    }

    if ($target === '' || $target === 'next') {
      $i++;
      continue;
    }

    $tid = str_starts_with($target, 'question:')
      ? substr($target, 9)
      : $target;

    if (!isset($ix[$tid])) {
      break;
    }

    $i = $ix[$tid];
  }

  return array_keys($out);
}

function validateAnswers(array $s, array $a): array {
  $e = [];
  $qm = qmap($s);
  $allow = array_fill_keys(reachable($s, $a), 1);

  foreach ($a as $qid => $v) {
    if (!isset($qm[$qid])) {
      $e[$qid] = '存在しない質問です。';
      continue;
    }

    if (!isset($allow[$qid])) {
      continue;
    }

    $q = $qm[$qid];
    $set = [];

    foreach (($q['choices'] ?? []) as $c) {
      $set[$c['id']] = 1;
    }

    if ($q['type'] === 'text') {
      if (!is_string($v) || mb_strlen($v) > 10000) {
        $e[$qid] = '自由記述の値が正しくありません。';
      }
    } elseif ($q['type'] === 'single') {
      if (!is_string($v) || !isset($set[$v])) {
        $e[$qid] = '選択肢が正しくありません。';
      }
    } else {
      if (!is_array($v) || count(array_unique($v)) !== count($v)) {
        $e[$qid] = '複数選択の値が正しくありません。';
      } else {
        foreach ($v as $x) {
          if (!isset($set[$x])) {
            $e[$qid] = '選択肢が正しくありません。';
          }
        }
      }
    }
  }

  foreach ($qm as $qid => $q) {
    if (isset($allow[$qid]) && ($q['required'] ?? false)) {
      $v = $a[$qid] ?? null;

      if (
        $v === null ||
        $v === '' ||
        ($q['type'] === 'multiple' && (!$v || count($v) === 0))
      ) {
        $e[$qid] = '必須項目です。';
      }
    }
  }

  return $e;
}

function safeHeaders(): array {
  return http_get_last_response_headers() ?? [];
}

function kurl(string $domain, string $endpoint): string {
  $d = trim($domain);
  $d = preg_replace('/^https?:\/\//i', '', $d) ?? '';
  $d = preg_replace('/\.cybozu\.com.*$/i', '', $d) ?? $d;
  $d = trim($d, " /\t\r\n");

  if ($d === '') {
    throw new RuntimeException('kintoneのサブドメインを設定してください。');
  }

  return 'https://' . $d . '.cybozu.com/' . ltrim($endpoint, '/');
}

function kreq(
  string $method,
  string $url,
  array $headers,
  mixed $payload = null,
  array $cfg = []
): array {
  $o = [
    'method' => strtoupper($method),
    'header' => implode("\r\n", $headers),
    'ignore_errors' => true,
    'timeout' => 20,
  ];

  if (strtoupper($method) !== 'GET' && $payload !== null) {
    $o['content'] = is_array($payload) ? jp($payload) : (string)$payload;
  }

  $co = [
    'http' => $o,
    'ssl' => [
      'verify_peer' => (bool)($cfg['verify_ssl'] ?? false),
      'verify_peer_name' => (bool)($cfg['verify_ssl'] ?? false),
    ],
  ];

  $proxy = trim((string)($cfg['proxy'] ?? ''));

  if ($proxy !== '') {
    $co['http']['proxy'] = 'tcp://' . $proxy;
    $co['http']['request_fulluri'] = true;
  }

  $ctx = stream_context_create($co);
  $body = @file_get_contents($url, false, $ctx);
  $hs = safeHeaders();
  $status = 500;

  if ($hs && preg_match('/HTTP\/\d\.\d\s+(\d+)/i', $hs[0], $m)) {
    $status = (int)$m[1];
  }

  $d = is_string($body) ? json_decode($body, true) : null;

  if ($body !== false && $status >= 200 && $status < 300) {
    return [
      'success' => true,
      'status' => $status,
      'data' => is_array($d) ? $d : [],
    ];
  }

  $msg = is_array($d)
    ? (string)($d['message'] ?? 'kintone API通信でエラーが発生しました。')
    : 'kintone API通信でエラーが発生しました。';

  if (is_array($d['errors'] ?? null)) {
    foreach ($d['errors'] as $field => $err) {
      if (is_array($err) && !empty($err['messages'])) {
        $msg .= ' ' . $field . ':' . implode(',', array_map('strval', $err['messages']));
      }
    }
  }

  return [
    'success' => false,
    'status' => $status,
    'message' => $msg,
    'data' => is_array($d) ? $d : [],
  ];
}

function krequestFromSettings(
  array $settings,
  string $method,
  string $endpoint,
  mixed $payload = null
): array {
  $k = $settings['kintone'] ?? [];
  $sub = (string)($k['subdomain'] ?? '');
  $login = (string)($k['login'] ?? '');
  $pass = (string)($k['password'] ?? '');

  if ($sub === '' || $login === '' || $pass === '') {
    throw new RuntimeException('kintoneの接続設定が未完了です。');
  }

  $headers = [
    'X-Cybozu-Authorization: ' . base64_encode(trim($login) . ':' . trim($pass)),
    'Accept: application/json',
  ];

  if (strtoupper($method) !== 'GET') {
    $headers[] = 'Content-Type: application/json';
  }

  return kreq(
    $method,
    kurl($sub, $endpoint),
    $headers,
    $payload,
    [
      'proxy' => $k['proxyHostPort'] ?? '',
      'verify_ssl' => (bool)($k['verifySsl'] ?? false),
    ]
  );
}

function smtpRead($s): array {
  $lines = [];

  while (!feof($s)) {
    $line = fgets($s, 4096);

    if ($line === false) {
      break;
    }

    $line = rtrim($line, "\r\n");
    $lines[] = $line;

    if (preg_match('/^\d{3} /', $line)) {
      break;
    }
  }

  $code = 0;

  if (preg_match('/^(\d{3})/', $lines[0] ?? '', $m)) {
    $code = (int)$m[1];
  }

  return [$code, $lines];
}

function smtpCmd($s, string $cmd, array $ok): void {
  fwrite($s, $cmd . "\r\n");
  [$code] = smtpRead($s);

  if (!in_array($code, $ok, true)) {
    throw new RuntimeException('SMTPサーバーから予期しない応答が返されました。');
  }
}

function smtpOpen(array $cfg) {
  $host = trim((string)($cfg['host'] ?? ''));
  $port = (int)($cfg['port'] ?? 0);
  $secure = (string)($cfg['secure'] ?? 'tls');
  $user = (string)($cfg['username'] ?? '');
  $pass = (string)($cfg['password'] ?? '');

  if ($host === '' || $port <= 0) {
    throw new RuntimeException('SMTPホストとポートを設定してください。');
  }

  $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

  $ctx = stream_context_create([
    'ssl' => [
      'verify_peer' => false,
      'verify_peer_name' => false,
      'allow_self_signed' => true,
    ],
  ]);

  $s = @stream_socket_client(
    $remote,
    $errno,
    $errstr,
    15,
    STREAM_CLIENT_CONNECT,
    $ctx
  );

  if ($s === false) {
    throw new RuntimeException('SMTPサーバーへ接続できません。');
  }

  stream_set_timeout($s, 15);
  [$code] = smtpRead($s);

  if ($code < 200 || $code >= 400) {
    fclose($s);
    throw new RuntimeException('SMTPサーバーの初期応答が不正です。');
  }

  smtpCmd($s, 'EHLO localhost', [250]);

  if ($secure === 'tls') {
    smtpCmd($s, 'STARTTLS', [220]);

    if (
      stream_socket_enable_crypto(
        $s,
        true,
        STREAM_CRYPTO_METHOD_TLS_CLIENT
      ) !== true
    ) {
      fclose($s);
      throw new RuntimeException('SMTPのTLS接続に失敗しました。');
    }

    smtpCmd($s, 'EHLO localhost', [250]);
  }

  if ($user !== '') {
    smtpCmd($s, 'AUTH LOGIN', [334]);
    smtpCmd($s, base64_encode($user), [334]);
    smtpCmd($s, base64_encode($pass), [235]);
  }

  return $s;
}

function smtpClose($s): void {
  if (is_resource($s)) {
    @fwrite($s, "QUIT\r\n");
    fclose($s);
  }
}

function mh(string $s): string {
  return preg_match('/^[\x20-\x7E]*$/', $s)
    ? $s
    : '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function smtpSend(
  array $cfg,
  string $to,
  string $toName,
  string $subject,
  string $body
): void {
  $from = trim((string)($cfg['fromEmail'] ?? ''));

  if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException('送信元メールアドレスが正しくありません。');
  }

  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException('送信先メールアドレスが正しくありません。');
  }

  $s = smtpOpen($cfg);

  try {
    smtpCmd($s, 'MAIL FROM:<' . $from . '>', [250]);
    smtpCmd($s, 'RCPT TO:<' . $to . '>', [250, 251]);
    smtpCmd($s, 'DATA', [354]);

    $h = [
      'From: ' . mh((string)($cfg['fromName'] ?? 'アンケート事務局')) . ' <' . $from . '>',
      'To: ' . mh($toName) . ' <' . $to . '>',
      'Subject: ' . mh($subject),
      'Date: ' . date(DATE_RFC2822),
      'MIME-Version: 1.0',
      'Content-Type: text/plain; charset=UTF-8',
      'Content-Transfer-Encoding: 8bit',
    ];

    $b = str_replace(["\r\n", "\r"], "\n", $body);
    $b = preg_replace('/^\./m', '..', $b) ?? $b;

    smtpCmd(
      $s,
      implode("\r\n", $h) . "\r\n\r\n" . str_replace("\n", "\r\n", $b) . "\r\n.",
      [250]
    );
  } finally {
    smtpClose($s);
  }
}

function responseJson(array $r, int $status = 200): never {
  while (ob_get_level() > 0) {
    ob_end_clean();
  }

  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo jp($r);
  exit;
}

function ok(mixed $d = null): never {
  responseJson(['ok' => true, 'data' => $d]);
}

function ng(
  string $code,
  string $msg,
  array $fields = [],
  int $status = 400
): never {
  responseJson(
    [
      'ok' => false,
      'error' => [
        'code' => $code,
        'message' => $msg,
        'fields' => $fields,
      ],
    ],
    $status
  );
}

function bodyJson(): array {
  $raw = file_get_contents('php://input');

  if ($raw === false || trim($raw) === '') {
    return [];
  }

  try {
    $v = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
  } catch (\Throwable) {
    ng('INVALID_REQUEST', 'リクエスト形式が正しくありません。');
  }

  if (!is_array($v)) {
    ng('INVALID_REQUEST', 'リクエスト形式が正しくありません。');
  }

  return $v;
}

function csrf(): void {
  $x = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  $e = $_SESSION[SKEY]['csrf'] ?? '';

  if (
    $e === '' ||
    !is_string($x) ||
    !hash_equals($e, $x)
  ) {
    ng('CSRF_ERROR', 'セッションが確認できません。', [], 403);
  }
}

function safeSettings(array $s): array {
  return [
    'smtp' => [
      'host' => (string)($s['smtp']['host'] ?? ''),
      'port' => (int)($s['smtp']['port'] ?? 587),
      'secure' => (string)($s['smtp']['secure'] ?? 'tls'),
      'username' => (string)($s['smtp']['username'] ?? ''),
      'fromEmail' => (string)($s['smtp']['fromEmail'] ?? ''),
      'fromName' => (string)($s['smtp']['fromName'] ?? ''),
      'passwordSet' => (string)($s['smtp']['password'] ?? '') !== '',
    ],
    'kintone' => [
      'subdomain' => (string)($s['kintone']['subdomain'] ?? ''),
      'appId' => (string)($s['kintone']['appId'] ?? ''),
      'login' => (string)($s['kintone']['login'] ?? ''),
      'nameField' => (string)($s['kintone']['nameField'] ?? ''),
      'emailField' => (string)($s['kintone']['emailField'] ?? ''),
      'proxyHostPort' => (string)($s['kintone']['proxyHostPort'] ?? ''),
      'verifySsl' => (bool)($s['kintone']['verifySsl'] ?? false),
      'passwordSet' => (string)($s['kintone']['password'] ?? '') !== '',
    ],
  ];
}

function statsFor(string $sid, array $responses, array $logs): array {
  $ls = array_values(array_filter(
    $logs,
    fn($x) => (string)($x['surveyId'] ?? '') === $sid
  ));

  $rs = array_values(array_filter(
    $responses,
    fn($x) => (string)($x['surveyId'] ?? '') === $sid
  ));

  $sent = array_values(array_filter(
    $ls,
    fn($x) => in_array($x['status'] ?? '', ['sent', 'answered'], true)
  ));

  $sc = [];
  $ac = [];

  foreach ($sent as $x) {
    if ($x['customerId'] ?? '') {
      $sc[(string)$x['customerId']] = true;
    }
  }

  foreach ($rs as $x) {
    if ($x['customerId'] ?? '') {
      $ac[(string)$x['customerId']] = true;
    }
  }

  $answered = 0;

  foreach ($ac as $id => $_) {
    if (isset($sc[$id])) {
      $answered++;
    }
  }

  $rate = count($sc)
    ? round($answered / count($sc) * 100, 1)
    : 0;

  $daily = [];

  foreach ($rs as $x) {
    $d = substr((string)($x['answeredAt'] ?? ''), 0, 10);

    if ($d) {
      $daily[$d] = ($daily[$d] ?? 0) + 1;
    }
  }

  ksort($daily);

  $rec = [];

  foreach ($sent as $x) {
    if ($x['customerId'] ?? '') {
      $rec[(string)$x['customerId']] = [
        'customerId' => $x['customerId'],
        'name' => $x['name'],
        'email' => $x['email'],
        'status' => 'sent',
        'sentAt' => $x['sentAt'] ?? '',
      ];
    }
  }

  foreach ($rs as $x) {
    if (
      ($x['customerId'] ?? '') !== '' &&
      isset($rec[(string)$x['customerId']])
    ) {
      $rec[(string)$x['customerId']]['status'] = 'answered';
    }
  }

  return [
    'responseCount' => count($rs),
    'sentCount' => count($sent),
    'deliveredCount' => count($sent),
    'responseRate' => $rate,
    'unansweredCount' => max(0, count($sc) - $answered),
    'daily' => $daily,
    'recipientStatuses' => array_values($rec),
  ];
}

function resultFor(array $s, array $responses): array {
  $rows = array_values(array_filter(
    $responses,
    fn($x) => (string)($x['surveyId'] ?? '') === (string)$s['id']
  ));

  $out = [];

  foreach (questions($s) as $q) {
    $target = [];

    foreach ($rows as $r) {
      $a = is_array($r['answers'] ?? null)
        ? $r['answers']
        : [];

      if (in_array($q['id'], reachable($s, $a), true)) {
        $target[] = $r;
      }
    }

    if ($q['type'] === 'text') {
      $texts = [];

      foreach ($target as $r) {
        if (($r['answers'][$q['id']] ?? '') !== '') {
          $texts[] = [
            'text' => $r['answers'][$q['id']],
            'answeredAt' => $r['answeredAt'] ?? '',
          ];
        }
      }

      $out[] = [
        'questionId' => $q['id'],
        'type' => 'text',
        'targetCount' => count($target),
        'answeredCount' => count($texts),
        'texts' => $texts,
      ];
    } else {
      $cnt = [];

      foreach (($q['choices'] ?? []) as $c) {
        $cnt[$c['id']] = 0;
      }

      foreach ($target as $r) {
        $v = $r['answers'][$q['id']] ?? null;

        foreach (is_array($v) ? $v : [$v] as $x) {
          if (isset($cnt[$x])) {
            $cnt[$x]++;
          }
        }
      }

      $cs = [];

      foreach (($q['choices'] ?? []) as $c) {
        $n = $cnt[$c['id']] ?? 0;
        $d = count($target);

        $cs[] = [
          'id' => $c['id'],
          'label' => $c['label'],
          'count' => $n,
          'percentage' => $d
            ? round($n / $d * 100, 1)
            : 0,
        ];
      }

      $out[] = [
        'questionId' => $q['id'],
        'type' => $q['type'],
        'targetCount' => count($target),
        'choices' => $cs,
      ];
    }
  }

  return $out;
}

function apiRun(): void {
  $api = (string)($_GET['api'] ?? '');

  if ($api === '') {
    return;
  }

  try {
    if (
      $api === 'bootstrap' &&
      ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    ) {
      $d = allData();
      $sd = [];

      foreach ($d['surveys'] as $s) {
        $sd[] = [
          'survey' => $s,
          'stats' => statsFor(
            (string)$s['id'],
            $d['responses'],
            $d['logs']
          ),
          'results' => resultFor($s, $d['responses']),
          'logs' => array_values(array_filter(
            $d['logs'],
            fn($x) => (string)($x['surveyId'] ?? '') === (string)$s['id']
          )),
        ];
      }

      ok([
        'csrfToken' => $_SESSION[SKEY]['csrf'],
        'surveys' => $d['surveys'],
        'customers' => $d['customers'],
        'surveyData' => $sd,
        'settings' => safeSettings($d['settings']),
      ]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
      ng(
        'INVALID_REQUEST',
        'このAPIはPOSTで呼び出してください。',
        [],
        405
      );
    }

    $b = bodyJson();

    if (in_array(
      $api,
      [
        'save_survey',
        'delete_survey',
        'publish',
        'close',
        'save_settings',
        'kintone_test',
        'sync_customers',
        'smtp_test',
        'send_mail',
        'resend_failed',
        'issue_answer_token',
      ],
      true
    )) {
      csrf();
    }

    $d = allData();

    if ($api === 'save_survey') {
      $s = $b['survey'] ?? null;

      if (!is_array($s)) {
        ng(
          'INVALID_REQUEST',
          'アンケートデータが正しくありません。'
        );
      }

      $s['name'] = trim((string)($s['name'] ?? ''));
      $s['description'] = (string)($s['description'] ?? '');
      $s['updatedAt'] = nowi();
      $s['id'] = (string)($s['id'] ?? rid('sv_'));
      $s['createdAt'] = $s['createdAt'] ?? nowi();
      $s['status'] = $s['status'] ?? 'draft';
      $s['groups'] = is_array($s['groups'] ?? null)
        ? $s['groups']
        : [];

      foreach ($s['groups'] as &$g) {
        $g['id'] = (string)($g['id'] ?? rid('g_'));
        $g['name'] = trim((string)($g['name'] ?? ''));
        $g['questions'] = is_array($g['questions'] ?? null)
          ? $g['questions']
          : [];

        foreach ($g['questions'] as &$q) {
          $q['id'] = (string)($q['id'] ?? rid('q_'));
          $q['text'] = (string)($q['text'] ?? '');
          $q['type'] = in_array(
            ($q['type'] ?? ''),
            ['text', 'single', 'multiple'],
            true
          )
            ? $q['type']
            : 'text';

          $q['required'] = (bool)($q['required'] ?? false);
          $q['choices'] = is_array($q['choices'] ?? null)
            ? $q['choices']
            : [];

          foreach ($q['choices'] as &$c) {
            $c['id'] = (string)($c['id'] ?? rid('c_'));
            $c['label'] = (string)($c['label'] ?? '');
          }

          unset($c);

          $q['branches'] = is_array($q['branches'] ?? null)
            ? $q['branches']
            : [];
        }

        unset($q);
      }

      unset($g);

      $er = validateSurvey($s);

      if ($er) {
        ng(
          'VALIDATION_ERROR',
          '入力内容を確認してください。',
          $er
        );
      }

      $i = surveyIndex($d['surveys'], $s['id']);

      if ($i >= 0) {
        $s['createdAt'] =
          $d['surveys'][$i]['createdAt'] ?? $s['createdAt'];

        $s['status'] =
          $d['surveys'][$i]['status'] ?? 'draft';

        $d['surveys'][$i] = $s;
      } else {
        $s['status'] = 'draft';
        $d['surveys'][] = $s;
      }

      writeJson('surveys.json', $d['surveys']);

      ok(['survey' => $s]);
    }

    if ($api === 'delete_survey') {
      $id = (string)($b['surveyId'] ?? '');
      $i = surveyIndex($d['surveys'], $id);

      if ($i < 0) {
        ng('NOT_FOUND', 'アンケートが見つかりません。', [], 404);
      }

      if (($d['surveys'][$i]['status'] ?? '') !== 'draft') {
        ng(
          'INVALID_STATE',
          '下書きのアンケートだけ削除できます。'
        );
      }

      $d['surveys'] = array_values(array_filter(
        $d['surveys'],
        fn($x) => (string)$x['id'] !== $id
      ));

      $d['responses'] = array_values(array_filter(
        $d['responses'],
        fn($x) => (string)($x['surveyId'] ?? '') !== $id
      ));

      $d['tokens'] = array_values(array_filter(
        $d['tokens'],
        fn($x) => (string)($x['surveyId'] ?? '') !== $id
      ));

      $d['logs'] = array_values(array_filter(
        $d['logs'],
        fn($x) => (string)($x['surveyId'] ?? '') !== $id
      ));

      writeJson('surveys.json', $d['surveys']);
      writeJson('responses.json', $d['responses']);
      writeJson('answer_tokens.json', $d['tokens']);
      writeJson('send_logs.json', $d['logs']);

      ok(['surveyId' => $id]);
    }

    if ($api === 'publish') {
      $id = (string)($b['surveyId'] ?? '');
      $i = surveyIndex($d['surveys'], $id);

      if ($i < 0) {
        ng('NOT_FOUND', 'アンケートが見つかりません。', [], 404);
      }

      if (($d['surveys'][$i]['status'] ?? '') !== 'draft') {
        ng(
          'INVALID_STATE',
          '下書きのアンケートだけ公開できます。'
        );
      }

      $er = validateSurvey($d['surveys'][$i]);

      if ($er) {
        ng(
          'VALIDATION_ERROR',
          '公開前に修正が必要です。',
          $er
        );
      }

      $d['surveys'][$i]['status'] = 'published';
      $d['surveys'][$i]['updatedAt'] = nowi();

      writeJson('surveys.json', $d['surveys']);

      ok(['survey' => $d['surveys'][$i]]);
    }

    if ($api === 'close') {
      $id = (string)($b['surveyId'] ?? '');
      $i = surveyIndex($d['surveys'], $id);

      if ($i < 0) {
        ng('NOT_FOUND', 'アンケートが見つかりません。', [], 404);
      }

      if (($d['surveys'][$i]['status'] ?? '') !== 'published') {
        ng(
          'INVALID_STATE',
          '公開中のアンケートだけ終了できます。'
        );
      }

      $d['surveys'][$i]['status'] = 'closed';
      $d['surveys'][$i]['updatedAt'] = nowi();

      writeJson('surveys.json', $d['surveys']);

      ok(['survey' => $d['surveys'][$i]]);
    }

    if ($api === 'save_settings') {
      $in = $b['settings'] ?? [];

      if (!is_array($in)) {
        ng(
          'INVALID_REQUEST',
          '設定データが正しくありません。'
        );
      }

      $old = $d['settings'];
      $smtp = $in['smtp'] ?? [];
      $k = $in['kintone'] ?? [];

      $new = [
        'smtp' => [
          'host' => trim((string)($smtp['host'] ?? '')),
          'port' => max(1, (int)($smtp['port'] ?? 587)),
          'secure' => in_array(
            ($smtp['secure'] ?? 'tls'),
            ['none', 'ssl', 'tls'],
            true
          )
            ? $smtp['secure']
            : 'tls',
          'username' => trim((string)($smtp['username'] ?? '')),
          'password' =>
            trim((string)($smtp['password'] ?? '')) !== ''
              ? (string)$smtp['password']
              : (string)($old['smtp']['password'] ?? ''),
          'fromEmail' => trim((string)($smtp['fromEmail'] ?? '')),
          'fromName' => trim(
            (string)($smtp['fromName'] ?? 'アンケート事務局')
          ),
        ],
        'kintone' => [
          'subdomain' => trim((string)($k['subdomain'] ?? '')),
          'appId' => trim((string)($k['appId'] ?? '')),
          'login' => trim((string)($k['login'] ?? '')),
          'password' =>
            trim((string)($k['password'] ?? '')) !== ''
              ? (string)$k['password']
              : (string)($old['kintone']['password'] ?? ''),
          'nameField' => trim((string)($k['nameField'] ?? '')),
          'emailField' => trim((string)($k['emailField'] ?? '')),
          'proxyHostPort' => trim((string)($k['proxyHostPort'] ?? '')),
          'verifySsl' => (bool)($k['verifySsl'] ?? false),
        ],
      ];

      writeJson('settings.json', $new);

      ok(['settings' => safeSettings($new)]);
    }

    if ($api === 'kintone_test') {
      $k = $d['settings']['kintone'] ?? [];
      $app = (int)($k['appId'] ?? 0);

      $ep = $app
        ? '/k/v1/app.json?' .
          http_build_query(
            ['id' => $app],
            '',
            '&',
            PHP_QUERY_RFC3986
          )
        : '/k/v1/apps.json?' .
          http_build_query(
            ['limit' => 1],
            '',
            '&',
            PHP_QUERY_RFC3986
          );

      $r = krequestFromSettings(
        $d['settings'],
        'GET',
        $ep
      );

      if (!$r['success']) {
        ng('KINTONE_ERROR', (string)$r['message']);
      }

      ok(['message' => 'kintoneへの接続に成功しました。']);
    }

    if ($api === 'sync_customers') {
      $k = $d['settings']['kintone'] ?? [];
      $app = (int)($k['appId'] ?? 0);
      $nf = (string)($k['nameField'] ?? '');
      $ef = (string)($k['emailField'] ?? '');

      if ($app <= 0 || $nf === '' || $ef === '') {
        ng(
          'VALIDATION_ERROR',
          'kintoneのアプリIDと顧客名・メールアドレスのフィールドコードを設定してください。'
        );
      }

      $records = [];
      $offset = 0;

      do {
        $params = [
          'app' => $app,
          'limit' => 500,
          'offset' => $offset,
        ];

        $r = krequestFromSettings(
          $d['settings'],
          'GET',
          '/k/v1/records.json?' .
          http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
          )
        );

        if (!$r['success']) {
          ng('KINTONE_ERROR', (string)$r['message']);
        }

        $batch = $r['data']['records'] ?? [];

        foreach ($batch as $rec) {
          $name = $rec[$nf]['value'] ?? '';
          $email = $rec[$ef]['value'] ?? '';
          $email = trim((string)$email);

          if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
          }

          $records[] = [
            'id' => 'k_' . (string)($rec['$id']['value'] ?? rid()),
            'name' => (string)$name,
            'email' => $email,
            'company' => '',
            'updatedAt' => nowi(),
          ];
        }

        $offset += 500;
      } while (count($batch) === 500);

      writeJson('customers.json', $records);

      ok([
        'customers' => $records,
        'count' => count($records),
      ]);
    }

    if ($api === 'smtp_test') {
      try {
        $s = smtpOpen($d['settings']['smtp'] ?? []);
        smtpClose($s);

        ok([
          'message' => 'SMTP接続と認証に成功しました。',
        ]);
      } catch (\Throwable $e) {
        ng(
          'SMTP_ERROR',
          $e->getMessage(),
          [],
          500
        );
      }
    }

    if ($api === 'send_mail') {
      $sid = (string)($b['surveyId'] ?? '');
      $ids = is_array($b['customerIds'] ?? null)
        ? array_map('strval', $b['customerIds'])
        : [];
      $sub = trim((string)($b['subject'] ?? ''));
      $body = (string)($b['body'] ?? '');

      $i = surveyIndex($d['surveys'], $sid);

      if ($i < 0) {
        ng('NOT_FOUND', 'アンケートが見つかりません。', [], 404);
      }

      if (($d['surveys'][$i]['status'] ?? '') !== 'published') {
        ng(
          'INVALID_STATE',
          '公開中のアンケートだけ送信できます。'
        );
      }

      if ($sub === '' || $body === '' || !$ids) {
        ng(
          'VALIDATION_ERROR',
          '送信対象者、件名、本文を確認してください。'
        );
      }

      $smtp = $d['settings']['smtp'] ?? [];

      if (
        trim((string)($smtp['host'] ?? '')) === '' ||
        trim((string)($smtp['fromEmail'] ?? '')) === ''
      ) {
        ng(
          'SMTP_ERROR',
          'SMTP設定を完了してから送信してください。'
        );
      }

      $cm = [];

      foreach ($d['customers'] as $c) {
        $cm[$c['id']] = $c;
      }

      $success = 0;
      $failed = 0;
      $results = [];

      foreach ($ids as $cid) {
        if (!isset($cm[$cid])) {
          $failed++;
          $results[] = [
            'customerId' => $cid,
            'status' => 'failed',
            'error' => '顧客が見つかりません。',
          ];
          continue;
        }

        $sentBefore = false;

        foreach ($d['logs'] as $l) {
          if (
            (string)($l['surveyId'] ?? '') === $sid &&
            (string)($l['customerId'] ?? '') === $cid &&
            in_array(
              $l['status'] ?? '',
              ['sent', 'answered'],
              true
            )
          ) {
            $sentBefore = true;
            break;
          }
        }

        if ($sentBefore) {
          $results[] = [
            'customerId' => $cid,
            'status' => 'skipped',
            'error' => '送信済み',
          ];
          continue;
        }

        $c = $cm[$cid];
        $token = bin2hex(random_bytes(24));
        $scheme =
          (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            ? 'https'
            : 'http';

        $host = $_SERVER['HTTP_HOST'] ?? '';
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';

        $url =
          $scheme .
          '://' .
          $host .
          $script .
          '?answer=' .
          rawurlencode($sid) .
          '&token=' .
          rawurlencode($token);

        $mail = str_replace(
          ['{{ANSWER_URL}}', '{{回答URL}}'],
          $url,
          $body
        );

        $log = [
          'id' => rid('log_'),
          'surveyId' => $sid,
          'customerId' => $cid,
          'email' => $c['email'],
          'name' => $c['name'],
          'subject' => $sub,
          'body' => $body,
          'status' => 'failed',
          'sentAt' => null,
          'answeredAt' => null,
          'error' => '',
          'tokenId' => null,
        ];

        try {
          smtpSend(
            $smtp,
            $c['email'],
            $c['name'],
            $sub,
            $mail
          );

          $log['status'] = 'sent';
          $log['sentAt'] = nows();
          $log['tokenId'] = $token;

          $d['tokens'][] = [
            'tokenId' => $token,
            'surveyId' => $sid,
            'customerId' => $cid,
            'email' => $c['email'],
            'issuedAt' => nowi(),
            'usedAt' => null,
          ];

          $success++;

          $results[] = [
            'customerId' => $cid,
            'status' => 'sent',
            'error' => '',
          ];
        } catch (\Throwable $e) {
          $log['error'] = $e->getMessage();
          $failed++;

          $results[] = [
            'customerId' => $cid,
            'status' => 'failed',
            'error' => $e->getMessage(),
          ];
        }

        $d['logs'][] = $log;
      }

      writeJson('send_logs.json', $d['logs']);
      writeJson('answer_tokens.json', $d['tokens']);

      ok([
        'total' => count($ids),
        'success' => $success,
        'failed' => $failed,
        'results' => $results,
        'sentAt' => nows(),
      ]);
    }

    if ($api === 'resend_failed') {
      $ids = is_array($b['logIds'] ?? null)
        ? array_map('strval', $b['logIds'])
        : [];

      if (!$ids) {
        ng(
          'VALIDATION_ERROR',
          '再送対象がありません。'
        );
      }

      $set = array_fill_keys($ids, true);
      $cm = [];

      foreach ($d['customers'] as $c) {
        $cm[$c['id']] = $c;
      }

      $okc = 0;
      $fc = 0;
      $smtp = $d['settings']['smtp'] ?? [];

      foreach ($d['logs'] as &$l) {
        if (
          !isset($set[$l['id']]) ||
          ($l['status'] ?? '') !== 'failed'
        ) {
          continue;
        }

        $sid = (string)$l['surveyId'];
        $cid = (string)$l['customerId'];
        $i = surveyIndex($d['surveys'], $sid);

        if (
          $i < 0 ||
          ($d['surveys'][$i]['status'] ?? '') !== 'published'
        ) {
          $fc++;
          continue;
        }

        $token = bin2hex(random_bytes(24));

        $scheme =
          (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            ? 'https'
            : 'http';

        $host = $_SERVER['HTTP_HOST'] ?? '';
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';

        $url =
          $scheme .
          '://' .
          $host .
          $script .
          '?answer=' .
          rawurlencode($sid) .
          '&token=' .
          rawurlencode($token);

        $mail = str_replace(
          ['{{ANSWER_URL}}', '{{回答URL}}'],
          $url,
          (string)$l['body']
        );

        try {
          smtpSend(
            $smtp,
            (string)$l['email'],
            (string)$l['name'],
            (string)$l['subject'],
            $mail
          );

          $l['status'] = 'sent';
          $l['sentAt'] = nows();
          $l['error'] = '';
          $l['tokenId'] = $token;

          $d['tokens'][] = [
            'tokenId' => $token,
            'surveyId' => $sid,
            'customerId' => $cid,
            'email' => $l['email'],
            'issuedAt' => nowi(),
            'usedAt' => null,
          ];

          $okc++;
        } catch (\Throwable $e) {
          $l['error'] = $e->getMessage();
          $fc++;
        }
      }

      unset($l);

      writeJson('send_logs.json', $d['logs']);
      writeJson('answer_tokens.json', $d['tokens']);

      ok([
        'success' => $okc,
        'failed' => $fc,
      ]);
    }

    if ($api === 'issue_answer_token') {
      $sid = (string)($b['surveyId'] ?? '');
      $i = surveyIndex($d['surveys'], $sid);

      if ($i < 0) {
        ng('NOT_FOUND', 'アンケートが見つかりません。', [], 404);
      }

      if (($d['surveys'][$i]['status'] ?? '') !== 'published') {
        ng(
          'INVALID_STATE',
          '公開中のアンケートだけ回答URLを発行できます。'
        );
      }

      $token = bin2hex(random_bytes(24));

      $d['tokens'][] = [
        'tokenId' => $token,
        'surveyId' => $sid,
        'customerId' => null,
        'email' => '',
        'issuedAt' => nowi(),
        'usedAt' => null,
      ];

      writeJson('answer_tokens.json', $d['tokens']);

      $scheme =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          ? 'https'
          : 'http';

      $host = $_SERVER['HTTP_HOST'] ?? '';
      $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';

      $url =
        $scheme .
        '://' .
        $host .
        $script .
        '?answer=' .
        rawurlencode($sid) .
        '&token=' .
        rawurlencode($token);

      ok([
        'url' => $url,
        'token' => $token,
      ]);
    }

    if ($api === 'submit_answer') {
      $sid = (string)($b['surveyId'] ?? '');
      $token = (string)($b['token'] ?? '');
      $a = is_array($b['answers'] ?? null)
        ? $b['answers']
        : [];

      $i = surveyIndex($d['surveys'], $sid);

      if ($i < 0) {
        ng('NOT_FOUND', 'アンケートが見つかりません。', [], 404);
      }

      $s = $d['surveys'][$i];

      if (($s['status'] ?? '') !== 'published') {
        ng(
          'INVALID_STATE',
          'このアンケートは現在回答を受け付けていません。'
        );
      }

      try {
        $now = new DateTimeImmutable();

        if (
          $now < new DateTimeImmutable($s['startAt']) ||
          $now > new DateTimeImmutable($s['endAt'])
        ) {
          ng(
            'OUT_OF_PERIOD',
            'このアンケートは現在回答期間外です。'
          );
        }
      } catch (\Throwable) {
        ng(
          'INVALID_STATE',
          '公開期間を確認できません。'
        );
      }

      $ti = -1;

      foreach ($d['tokens'] as $n => $t) {
        if (
          (string)($t['tokenId'] ?? '') === $token &&
          (string)($t['surveyId'] ?? '') === $sid
        ) {
          $ti = $n;
          break;
        }
      }

      if ($ti < 0) {
        ng(
          'INVALID_TOKEN',
          '回答URLが正しくありません。',
          [],
          403
        );
      }

      if (!empty($d['tokens'][$ti]['usedAt'])) {
        ng(
          'TOKEN_USED',
          'この回答URLはすでに回答済みです。',
          [],
          409
        );
      }

      $er = validateAnswers($s, $a);

      if ($er) {
        ng(
          'VALIDATION_ERROR',
          '入力内容を確認してください。',
          $er
        );
      }

      $allow = array_fill_keys(
        reachable($s, $a),
        true
      );

      $clean = [];

      foreach ($a as $qid => $v) {
        if (isset($allow[$qid])) {
          $clean[$qid] = $v;
        }
      }

      $t = $d['tokens'][$ti];

      $r = [
        'id' => rid('resp_'),
        'surveyId' => $sid,
        'tokenId' => $token,
        'customerId' => $t['customerId'] ?? null,
        'customerName' => '',
        'customerEmail' => (string)($t['email'] ?? ''),
        'answeredAt' => nowi(),
        'answers' => $clean,
      ];

      foreach ($d['customers'] as $c) {
        if ((string)$c['id'] === (string)($t['customerId'] ?? '')) {
          $r['customerName'] = $c['name'];
          break;
        }
      }

      $d['responses'][] = $r;
      $d['tokens'][$ti]['usedAt'] = nowi();

      foreach ($d['logs'] as &$l) {
        if ((string)($l['tokenId'] ?? '') === $token) {
          $l['status'] = 'answered';
          $l['answeredAt'] = $r['answeredAt'];
          break;
        }
      }

      unset($l);

      writeJson('responses.json', $d['responses']);
      writeJson('answer_tokens.json', $d['tokens']);
      writeJson('send_logs.json', $d['logs']);

      ok(['responseId' => $r['id']]);
    }

    ng(
      'NOT_FOUND',
      '指定されたAPIは存在しません。',
      [],
      404
    );
  } catch (\Throwable $e) {
    error_log(
      '[QuestionnaireOperations] ' . $e->getMessage()
    );

    ng(
      'SERVER_ERROR',
      '処理中にエラーが発生しました。',
      [],
      500
    );
  }
}

initData();
apiRun();

$answer = (string)($_GET['answer'] ?? '');
$token = (string)($_GET['token'] ?? '');
$preview = (string)($_GET['preview'] ?? '');

$mode = 'admin';
$context = null;
$message = '';

if ($answer !== '') {
  $mode = 'answer';

  try {
    $d = allData();
    $i = surveyIndex($d['surveys'], $answer);

    if ($i < 0) {
      $message = 'アンケートが見つかりません。';
    } else {
      $s = $d['surveys'][$i];
      $t = null;

      foreach ($d['tokens'] as $x) {
        if (
          (string)($x['tokenId'] ?? '') === $token &&
          (string)($x['surveyId'] ?? '') === $answer
        ) {
          $t = $x;
          break;
        }
      }

      if (($s['status'] ?? '') !== 'published') {
        $message = 'このアンケートは現在回答を受け付けていません。';
      } elseif (!$t) {
        $message = '回答URLが正しくありません。';
      } elseif (!empty($t['usedAt'])) {
        $message = 'この回答URLはすでに回答済みです。';
      } else {
        try {
          $n = new DateTimeImmutable();

          if (
            $n < new DateTimeImmutable($s['startAt']) ||
            $n > new DateTimeImmutable($s['endAt'])
          ) {
            $message = 'このアンケートは現在回答期間外です。';
          } else {
            $context = [
              'survey' => $s,
              'token' => $token,
              'preview' => false,
            ];
          }
        } catch (\Throwable) {
          $message = '公開期間を確認できません。';
        }
      }
    }
  } catch (\Throwable) {
    $message = '回答画面の読み込みに失敗しました。';
  }
}

if ($preview !== '') {
  $mode = 'preview';

  try {
    $d = allData();
    $i = surveyIndex($d['surveys'], $preview);

    if ($i < 0) {
      $message = 'アンケートが見つかりません。';
    } else {
      $context = [
        'survey' => $d['surveys'][$i],
        'token' => '',
        'preview' => true,
      ];
    }
  } catch (\Throwable) {
    $message = 'プレビュー画面の読み込みに失敗しました。';
  }
}

$ctx = $context
  ? json_encode(
      $context,
      JSON_UNESCAPED_UNICODE |
      JSON_UNESCAPED_SLASHES |
      JSON_HEX_TAG |
      JSON_HEX_AMP |
      JSON_HEX_APOS |
      JSON_HEX_QUOT
    )
  : 'null';
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>アンケート業務運営アプリ</title>
<style>
:root{--p:#2563eb;--pd:#1d4ed8;--bg:#f8fafc;--s:#fff;--b:#e2e8f0;--t:#1e293b;--m:#64748b;--ok:#16a34a;--ng:#dc2626;--wa:#d97706}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--t);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}button,input,select,textarea{font:inherit}button{cursor:pointer}button:disabled{cursor:not-allowed;opacity:.6}.hdr{background:#fff;border-bottom:1px solid var(--b);position:sticky;top:0;z-index:20}.hdrin{max-width:1200px;margin:auto;padding:0 18px;min-height:62px;display:flex;align-items:center;gap:20px}.logo{font-weight:800;color:var(--p);white-space:nowrap}.nav{display:flex;gap:2px;overflow:auto}.nav button{border:0;background:none;padding:20px 12px;color:var(--m);border-bottom:2px solid transparent;white-space:nowrap}.nav button.on{color:var(--p);border-bottom-color:var(--p);font-weight:700}.page{max-width:1200px;margin:auto;padding:24px 18px 50px}.bar{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:18px}.title{font-size:24px;font-weight:800;margin:0}.sub{color:var(--m);margin:5px 0 0}.card{background:#fff;border:1px solid var(--b);border-radius:12px;padding:18px;margin-bottom:16px}.actions{display:flex;flex-wrap:wrap;gap:7px}.btn{border:1px solid var(--b);background:#fff;color:var(--t);border-radius:8px;padding:8px 12px;font-weight:700}.btn:hover:not(:disabled){transform:translateY(-1px)}.primary{background:var(--p);color:#fff;border-color:var(--p)}.danger{background:var(--ng);color:#fff;border-color:var(--ng)}.warning{background:#f59e0b;color:#fff;border-color:#f59e0b}.success{background:var(--ok);color:#fff;border-color:var(--ok)}.small{font-size:12px}.muted{color:var(--m)}.badge{display:inline-flex;border-radius:999px;padding:3px 8px;font-size:12px;font-weight:700}.draft{background:#e2e8f0;color:#475569}.pub{background:#dcfce7;color:#166534}.closed{background:#fee2e2;color:#991b1b}.info{background:#dbeafe;color:#1d4ed8}.wrap{overflow:auto;border:1px solid var(--b);border-radius:9px}table{width:100%;min-width:760px;border-collapse:collapse}th,td{padding:11px 12px;border-bottom:1px solid var(--b);text-align:left;vertical-align:middle}th{background:#f8fafc;color:var(--m);font-size:12px}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:13px}.grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.fg{margin-bottom:13px}.fg label{display:block;font-size:13px;font-weight:700;margin-bottom:5px}.req{color:var(--ng)}.ctl{width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff}.ctl:focus{outline:2px solid #bfdbfe;border-color:var(--p)}.alert{border-radius:9px;padding:10px 12px;margin:10px 0}.a-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8}.a-ng{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.a-ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}.subnav{background:#f1f5f9;border-bottom:1px solid var(--b)}.subnavin{max-width:1200px;margin:auto;padding:7px 18px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}.subname{font-weight:800;margin-right:auto}.tab{border:0;background:transparent;padding:8px 10px;border-radius:7px;color:var(--m)}.tab.on{background:#fff;color:var(--p);font-weight:800}.group{border:1px solid var(--b);border-radius:10px;margin-bottom:12px;overflow:hidden;background:#fbfdff}.gh{background:#f1f5f9;padding:9px 11px;display:flex;align-items:center;gap:8px}.gb{padding:11px}.q{background:#fff;border:1px solid var(--b);border-radius:9px;padding:11px;margin-bottom:9px}.qgrid{display:grid;grid-template-columns:55px 1fr auto;gap:9px}.qn{font-weight:800;color:var(--p);padding-top:8px}.choice{display:grid;grid-template-columns:1fr auto;gap:7px;margin:6px 0}.branch{display:grid;grid-template-columns:1fr 280px;gap:8px;align-items:center;margin:6px 0}.stat{border:1px solid var(--b);border-radius:9px;padding:13px;background:#fff}.stat b{display:block;font-size:25px;margin-top:3px}.barbg{height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden}.barbg i{display:block;height:100%;background:var(--p)}.toast{position:fixed;right:16px;bottom:16px;z-index:60;background:#0f172a;color:#fff;padding:10px 13px;border-radius:8px;box-shadow:0 9px 25px #0002;max-width:380px}.modalbg{position:fixed;inset:0;background:#0f172a66;display:flex;align-items:center;justify-content:center;padding:18px;z-index:100}.modal{background:#fff;border-radius:12px;padding:18px;width:min(620px,100%)}.mact{display:flex;justify-content:flex-end;gap:7px;margin-top:16px}.respond{min-height:100vh;padding:30px 15px;background:var(--bg)}.respondcard{max-width:820px;margin:auto;background:#fff;border:1px solid var(--b);border-radius:13px;padding:25px}.rq{padding:16px 0;border-top:1px solid var(--b)}.rqt{font-weight:800;margin-bottom:8px}.rchoice{display:flex;gap:8px;margin:8px 0}.done{text-align:center;padding:35px 15px}.preview{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;padding:9px;border-radius:8px;margin-bottom:13px}.empty{text-align:center;padding:35px;color:var(--m)}@media(max-width:850px){.grid2,.grid4{grid-template-columns:1fr 1fr}.qgrid{grid-template-columns:45px 1fr}.qgrid>.actions{grid-column:1/-1}.branch{grid-template-columns:1fr}}@media(max-width:600px){.grid2,.grid4{grid-template-columns:1fr}.hdrin{align-items:flex-start;flex-direction:column;padding-top:9px}.nav{width:100%}.nav button{padding:11px}.page{padding:18px 12px 38px}}
</style>
</head>
<body>
<div id="app"></div>
<div id="toast"></div>
<div id="modal"></div>

<script>
window.APP_CONTEXT=<?=$ctx?>;
window.APP_MODE=<?=json_encode($mode,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
window.APP_MESSAGE=<?=json_encode($message,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;

document.addEventListener('DOMContentLoaded',()=>{
'use strict';

const app=document.getElementById('app');
const toastEl=document.getElementById('toast');
const modal=document.getElementById('modal');
const fileMode=location.protocol==='file:';

/* APIは表示中のindex.phpを基準に解決 */
const API=new URL('index.php',document.baseURI).href;

const st={
  page:'surveys',
  sid:'',
  tab:'content',
  csrf:'',
  surveys:[],
  customers:[],
  sd:[],
  settings:null,
  editor:null,
  dirty:false,
  csearch:'',
  selected:[],
  subject:'アンケートご協力のお願い',
  body:'いつもありがとうございます。\n以下のURLからアンケートへのご回答をお願いいたします。\n\n{{ANSWER_URL}}\n\nよろしくお願いいたします。'
};

const esc=v=>String(v??'')
  .replace(/&/g,'&amp;')
  .replace(/</g,'&lt;')
  .replace(/>/g,'&gt;')
  .replace(/"/g,'&quot;')
  .replace(/'/g,'&#039;');

const toast=(m,err=false)=>{
  toastEl.textContent=m;
  toastEl.style.background=err?'#991b1b':'#0f172a';
  toastEl.style.display='block';
  clearTimeout(toast.t);
  toast.t=setTimeout(()=>toastEl.style.display='none',3500);
};

const fmt=v=>{
  if(!v)return '-';
  const d=new Date(v);
  return isNaN(d)?v:d.toLocaleString('ja-JP');
};

const sc=s=>({draft:'draft',published:'pub',closed:'closed'})[s]||'draft';
const sl=s=>({draft:'下書き',published:'公開中',closed:'終了'})[s]||s;
const typeLabel=t=>({text:'自由記述',single:'単一選択',multiple:'複数選択'})[t]||t;
const uid=p=>p+Date.now().toString(36)+Math.random().toString(36).slice(2,7);
const badge=s=>`<span class="badge ${sc(s)}">${esc(sl(s))}</span>`;

function qs(s){
  return (s?.groups||[]).flatMap(g=>(g.questions||[]));
}

function nums(s){
  const r={};
  let n=1,g=1;

  for(const gr of s.groups||[]){
    let q=1;

    for(const x of gr.questions||[]){
      r[x.id]=s.numberingFormat==='global'?`Q${n}`:`Q${g}-${q}`;
      n++;
      q++;
    }

    g++;
  }

  return r;
}

function survey(id=st.sid){
  return st.surveys.find(x=>String(x.id)===String(id))||null;
}

function sd(id=st.sid){
  return st.sd.find(x=>String(x.survey?.id)===String(id))||null;
}

function api(name,payload=null,btn=null){
  if(fileMode){
    toast('Apache経由でindex.phpを開いてください。',true);
    return Promise.resolve(null);
  }

  if(btn){
    btn.disabled=true;
    btn.dataset.old=btn.textContent;
    btn.textContent='処理中…';
  }

  const endpoint=new URL(API);
  endpoint.searchParams.set('api',name);

  const o={
    method:payload===null?'GET':'POST',
    headers:{Accept:'application/json'}
  };

  if(payload!==null){
    o.headers['Content-Type']='application/json';
    o.headers['X-CSRF-Token']=st.csrf;
    o.body=JSON.stringify(payload);
  }

  return fetch(endpoint.toString(),o)
    .then(r=>r.json())
    .then(x=>{
      if(!x.ok){
        const f=x.error?.fields
          ?Object.values(x.error.fields).join('\n')
          :'';

        throw new Error(
          f
            ?(x.error?.message||'エラー')+'\n'+f
            :(x.error?.message||'処理に失敗しました。')
        );
      }

      return x.data;
    })
    .catch(e=>{
      toast(e.message||'通信エラー',true);
      return null;
    })
    .finally(()=>{
      if(btn){
        btn.disabled=false;
        btn.textContent=btn.dataset.old||btn.textContent;
      }
    });
}

async function reload(){
  const d=await api('bootstrap');

  if(!d)return false;

  st.csrf=d.csrfToken||st.csrf;
  st.surveys=d.surveys||[];
  st.customers=d.customers||[];
  st.sd=d.surveyData||[];
  st.settings=d.settings||st.settings;

  return true;
}

function confirmBox(title,msg,yes,label='実行する',danger=false){
  modal.innerHTML=
    `<div class="modalbg"><div class="modal">
      <h3>${esc(title)}</h3>
      <div style="white-space:pre-wrap">${esc(msg)}</div>
      <div class="mact">
        <button class="btn" data-mcancel>キャンセル</button>
        <button class="btn ${danger?'danger':'primary'}" data-myes>${esc(label)}</button>
      </div>
    </div></div>`;

  const c=modal.querySelector('[data-mcancel]');
  const y=modal.querySelector('[data-myes]');

  if(c)c.onclick=()=>modal.innerHTML='';
  if(y)y.onclick=async()=>{
    await yes(y);
    modal.innerHTML='';
  };
}

function nav(page,sid='',tab='content'){
  if(
    st.dirty &&
    page!=='editor' &&
    !confirm('保存していない変更があります。このまま移動しますか？')
  ){
    return;
  }

  st.dirty=false;
  st.page=page;
  st.sid=sid;
  st.tab=tab;
  st.selected=[];

  if(page!=='editor'){
    st.editor=null;
  }

  const p=new URLSearchParams();

  if(page==='editor'){
    p.set('page','editor');
  }else if(page==='survey'){
    p.set('page','survey');
    p.set('id',sid);
    p.set('tab',tab);
  }else if(page!=='surveys'){
    p.set('page',page);
  }

  const pageUrl=new URL(API);
  pageUrl.search=p.toString();

  history.replaceState(
    null,
    '',
    pageUrl.toString()
  );

  render();
}

function shell(body,on){
  return `<header class="hdr"><div class="hdrin">
    <div class="logo" data-nav=surveys>アンケート業務運営アプリ</div>
    <nav class="nav">
      <button class="${on==='surveys'?'on':''}" data-nav=surveys>アンケート一覧</button>
      <button class="${on==='editor'?'on':''}" data-nav=new>新規アンケート作成</button>
      <button class="${on==='customers'?'on':''}" data-nav=customers>顧客一覧</button>
      <button class="${on==='settings'?'on':''}" data-nav=settings>設定</button>
    </nav>
  </div></header>${body}`;
}

function surveysPage(){
  const rows=st.surveys.map(s=>{
    const d=sd(s.id);

    const ops=[
      `<button class="btn" data-act=open data-id="${esc(s.id)}">開く</button>`,
      `<button class="btn" data-act=edit data-id="${esc(s.id)}">編集</button>`
    ];

    if(s.status==='draft'){
      ops.push(
        `<button class="btn success" data-act=publish data-id="${esc(s.id)}">公開する</button>`
      );
    }

    if(s.status==='published'){
      ops.push(
        `<button class="btn warning" data-act=close data-id="${esc(s.id)}">終了する</button>`
      );
    }

    if(s.status==='draft'){
      ops.push(
        `<button class="btn" data-act=delete data-id="${esc(s.id)}">削除</button>`
      );
    }

    return `<tr>
      <td>
        <b>${esc(s.name||'(無題)')}</b>
        <div class="small muted">ID: ${esc(s.id)}</div>
      </td>
      <td>${badge(s.status)}</td>
      <td>${esc(fmt(s.createdAt))}<br>${esc(fmt(s.updatedAt))}</td>
      <td>${esc(fmt(s.startAt))}<br>～ ${esc(fmt(s.endAt))}</td>
      <td>${d?.stats?.responseCount||0} 件</td>
      <td><div class="actions">${ops.join('')}</div></td>
    </tr>`;
  }).join('');

  return shell(
    `<main class="page">
      <div class="bar">
        <div>
          <h1 class="title">アンケート一覧</h1>
          <div class="sub">作成済みアンケートを管理します。</div>
        </div>
        <button class="btn primary" data-nav=new>＋ 新規アンケートを作成</button>
      </div>
      <div class="card">
        <div class="wrap">
          <table>
            <thead>
              <tr>
                <th>アンケート名</th>
                <th>状態</th>
                <th>作成 / 更新</th>
                <th>公開期間</th>
                <th>回答数</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>
              ${rows||'<tr><td colspan="6"><div class="empty">アンケートはまだありません。</div></td></tr>'}
            </tbody>
          </table>
        </div>
      </div>
    </main>`,
    'surveys'
  );
}

function emptySurvey(){
  const a=new Date();
  const b=new Date(Date.now()+30*86400000);

  const d=x=>{
    const p=n=>String(n).padStart(2,'0');
    return `${x.getFullYear()}-${p(x.getMonth()+1)}-${p(x.getDate())}T${p(x.getHours())}:${p(x.getMinutes())}`;
  };

  return {
    id:uid('sv_'),
    name:'',
    description:'',
    status:'draft',
    startAt:d(a),
    endAt:d(b),
    numberingFormat:'group',
    groups:[
      {
        id:uid('g_'),
        name:'基本情報',
        questions:[
          {
            id:uid('q_'),
            text:'',
            type:'single',
            required:true,
            choices:[
              {id:uid('c_'),label:'選択肢1'},
              {id:uid('c_'),label:'選択肢2'}
            ],
            branches:{}
          }
        ]
      }
    ],
    createdAt:new Date().toISOString(),
    updatedAt:new Date().toISOString()
  };
}

function editorPage(){
  if(!st.editor)st.editor=emptySurvey();

  const s=st.editor;
  const n=nums(s);
  const all=qs(s);

  const gs=(s.groups||[]).map((g,gi)=>{
    const qh=(g.questions||[]).map((q,qi)=>{
      const ch=(q.choices||[]).map((c,ci)=>
        `<div class="choice">
          <input class="ctl" data-ed=choice data-gi=${gi} data-qi=${qi} data-ci=${ci} value="${esc(c.label)}">
          <button class="btn" data-act=rmchoice data-gi=${gi} data-qi=${qi} data-ci=${ci}>削除</button>
        </div>`
      ).join('');

      const br=q.type==='single'
        ?(q.choices||[]).map(c=>{
          const cur=q.branches?.[c.id]||'next';

          let op=
            `<option value=next ${cur==='next'?'selected':''}>次の質問</option>`;

          for(const t of all){
            if(t.id!==q.id){
              op+=
                `<option value="question:${esc(t.id)}" ${cur===`question:${t.id}`?'selected':''}>
                  ${esc(n[t.id]||t.id)} ${esc(t.text||'(無題)')}
                </option>`;
            }
          }

          op+=
            `<option value=end ${cur==='end'?'selected':''}>アンケートを終了</option>`;

          return `<div class="branch">
            <span>${esc(c.label)}</span>
            <select class="ctl" data-ed=branch data-gi=${gi} data-qi=${qi} data-cid="${esc(c.id)}">${op}</select>
          </div>`;
        }).join('')
        :'';

      return `<div class="q" draggable=true data-dq data-gi=${gi} data-qi=${qi}>
        <div class="qgrid">
          <div class="qn">${esc(n[q.id]||'')}</div>
          <div>
            <input class="ctl" data-ed=qtext data-gi=${gi} data-qi=${qi} value="${esc(q.text)}" placeholder="質問文を入力してください">

            <div class="grid2" style="margin-top:9px">
              <div class="fg">
                <label>回答形式</label>
                <select class="ctl" data-ed=type data-gi=${gi} data-qi=${qi}>
                  <option value=single ${q.type==='single'?'selected':''}>単一選択</option>
                  <option value=multiple ${q.type==='multiple'?'selected':''}>複数選択</option>
                  <option value=text ${q.type==='text'?'selected':''}>自由記述</option>
                </select>
              </div>

              <div class="fg">
                <label>必須</label>
                <label style="font-weight:400;padding-top:9px">
                  <input type=checkbox data-ed=req data-gi=${gi} data-qi=${qi} ${q.required?'checked':''}>
                  回答必須
                </label>
              </div>
            </div>

            ${
              q.type!=='text'
                ? `<div class="small muted" style="margin:7px 0">選択肢</div>
                   ${ch}
                   <button class="btn" data-act=addchoice data-gi=${gi} data-qi=${qi}>＋ 選択肢を追加</button>`
                :''
            }

            ${
              q.type==='single'
                ? `<div class="small muted" style="margin:13px 0 7px">回答による分岐</div>${br}`
                :''
            }
          </div>

          <div class="actions">
            <button class="btn" data-act=upq data-gi=${gi} data-qi=${qi}>↑</button>
            <button class="btn" data-act=downq data-gi=${gi} data-qi=${qi}>↓</button>
            <button class="btn" data-act=rmq data-gi=${gi} data-qi=${qi}>削除</button>
          </div>
        </div>
      </div>`;
    }).join('');

    return `<div class="group" draggable=true data-dg data-gi=${gi}>
      <div class="gh">
        <span>☷</span>
        <b>グループ ${gi+1}</b>
        <input class="ctl" style="max-width:380px" data-ed=gname data-gi=${gi} value="${esc(g.name)}">

        <div style="margin-left:auto" class="actions">
          <button class="btn" data-act=upg data-gi=${gi}>↑</button>
          <button class="btn" data-act=downg data-gi=${gi}>↓</button>
          <button class="btn" data-act=rmg data-gi=${gi}>グループ削除</button>
        </div>
      </div>

      <div class="gb">
        ${qh||'<div class="empty" style="padding:20px">質問がありません。</div>'}
        <button class="btn" data-act=addq data-gi=${gi}>＋ 質問を追加</button>
      </div>
    </div>`;
  }).join('');

  return shell(
    `<main class="page">
      <div class="bar">
        <div>
          <h1 class="title">${getSurveyName(s.id)?'アンケート編集':'新規アンケート作成'}</h1>
          <div class="sub">基本情報・グループ・質問・分岐を1画面で編集します。</div>
        </div>

        <div class="actions">
          <button class="btn" data-act=cancel>キャンセル</button>
          <button class="btn primary" data-act=save>保存する</button>
        </div>
      </div>

      <div class="card">
        <div class="grid2">
          <div class="fg">
            <label>アンケート名 <span class=req>*</span></label>
            <input class=ctl data-ed=name value="${esc(s.name)}">
          </div>

          <div class="fg">
            <label>質問番号</label>
            <select class=ctl data-ed=num>
              <option value=group ${s.numberingFormat==='group'?'selected':''}>グループ別</option>
              <option value=global ${s.numberingFormat==='global'?'selected':''}>全体通番</option>
            </select>
          </div>
        </div>

        <div class="fg">
          <label>説明文 / 案内文</label>
          <textarea class=ctl rows=3 data-ed=desc>${esc(s.description)}</textarea>
        </div>

        <div class=grid2>
          <div class=fg>
            <label>公開開始日時</label>
            <input class=ctl type=datetime-local data-ed=start value="${esc(String(s.startAt||'').slice(0,16))}">
          </div>

          <div class=fg>
            <label>公開終了日時</label>
            <input class=ctl type=datetime-local data-ed=end value="${esc(String(s.endAt||'').slice(0,16))}">
          </div>
        </div>

        <div class=a-info alert>状態：${badge(s.status)}</div>
      </div>

      <div class=card>
        <div class=bar>
          <div>
            <h2 style="margin:0">質問グループ</h2>
            <div class="small muted">グループ・質問はドラッグ＆ドロップまたは矢印で並び替えできます。</div>
          </div>

          <button class="btn primary" data-act=addg>＋ グループを追加</button>
        </div>

        <div id=groups>
          ${gs||'<div class=empty>グループがありません。</div>'}
        </div>
      </div>

      <div class=actions style="justify-content:flex-end">
        <button class="btn" data-act=cancel>キャンセル</button>
        <button class="btn primary" data-act=save>保存する</button>
      </div>
    </main>`,
    'editor'
  );
}

function getSurveyName(id){
  return st.surveys.some(x=>String(x.id)===String(id));
}

function detail(s){
  const n=nums(s);

  const body=(s.groups||[]).map(g=>
    `<div style="margin:0 0 18px">
      <h3 style="margin:0 0 7px">📁 ${esc(g.name)}</h3>
      ${(g.questions||[]).map(q=>
        `<div style="border-bottom:1px dashed var(--b);padding:9px 0">
          <b style="color:var(--p)">${esc(n[q.id])}</b>
          <b>${esc(q.text||'(無題)')}</b>
          <span class="badge info">${esc(typeLabel(q.type))}</span>
          <span class="small ${q.required?'req':'muted'}">${q.required?'*必須':'任意'}</span>
          ${
            q.choices?.length
              ? `<ul>${q.choices.map(c=>
                  `<li>
                    ${esc(c.label)}
                    ${
                      q.branches?.[c.id]&&q.branches[c.id]!=='next'
                        ? ` <span class=small style="color:var(--p)">→ ${
                            esc(
                              q.branches[c.id]==='end'
                                ? '終了'
                                : (
                                  n[q.branches[c.id].replace(/^question:/,'')]
                                  ||q.branches[c.id]
                                )
                            )
                          }</span>`
                        :''
                    }
                  </li>`
                ).join('')}</ul>`
              :''
          }
        </div>`
      ).join('')}
    </div>`
  ).join('');

  return `<div class=card>
    <div class=bar>
      <div>
        <h2 style="margin:0">${esc(s.name)}</h2>
        <div class="small muted">
          ${esc(fmt(s.startAt))} ～ ${esc(fmt(s.endAt))}
          ${badge(s.status)}
        </div>
      </div>

      <div class=actions>
        <button class="btn primary" data-act=edit data-id="${esc(s.id)}">内容を編集する</button>
        <button class="btn" data-act=publicurl data-id="${esc(s.id)}">回答URLを発行</button>
        <button class="btn" data-act=preview data-id="${esc(s.id)}">回答者プレビュー</button>
      </div>
    </div>

    <p style="white-space:pre-wrap">${esc(s.description||'')}</p>
    ${body||'<div class=empty>質問がありません。</div>'}
  </div>`;
}

function sendPage(s){
  const d=sd(s.id);
  const logs=d?.logs||[];
  const q=st.csearch.toLowerCase();

  const cs=st.customers.filter(
    c=>`${c.name} ${c.email} ${c.company}`.toLowerCase().includes(q)
  );

  const list=cs.map(c=>
    `<label style="display:flex;gap:8px;padding:8px;border:1px solid var(--b);border-radius:8px;margin:5px 0">
      <input type=checkbox data-act=customer data-id="${esc(c.id)}" ${st.selected.includes(c.id)?'checked':''}>
      <span>
        <b>${esc(c.name)}</b><br>
        <span class=small muted>
          ${esc(c.email)}${c.company?' / '+esc(c.company):''}
        </span>
      </span>
    </label>`
  ).join('');

  const fail=logs.filter(x=>x.status==='failed').length;

  return `<div class=card>
    <div class=bar>
      <div>
        <h2 style="margin:0">送信</h2>
        <div class="small muted">kintone等から取得した顧客へ回答依頼を送信します。</div>
      </div>
      <b>${st.selected.length}名選択中</b>
    </div>

    <div class=actions>
      <input id=cs class=ctl style="max-width:420px" placeholder="氏名・メールアドレス・会社名で検索" value="${esc(st.csearch)}">
      <button class=btn data-act=all>全選択</button>
      <button class=btn data-act=none>全解除</button>
    </div>

    <div style="margin-top:12px;max-height:300px;overflow:auto">
      ${list||'<div class=empty>顧客がありません。</div>'}
    </div>

    <div class=grid2 style="margin-top:15px">
      <div class=fg>
        <label>件名</label>
        <input id=sub class=ctl value="${esc(st.subject)}">
      </div>

      <div class=fg>
        <label>回答URL</label>
        <input class=ctl value="{{ANSWER_URL}}" readonly>
      </div>
    </div>

    <div class=fg>
      <label>本文</label>
      <textarea id=mb class=ctl rows=9>${esc(st.body)}</textarea>
    </div>

    <div class=a-info alert>
      <b>{{ANSWER_URL}}</b> を対象者ごとの回答URLへ置換して送信します。
    </div>

    <div class=actions style="justify-content:flex-end">
      <button class=btn data-act=mailpreview>送信プレビュー</button>
      <button class="btn primary" data-act=send>送信する</button>
    </div>
  </div>

  <div class=card>
    <div class=bar>
      <h2 style="margin:0">送信履歴</h2>
      ${fail?'<button class="btn primary" data-act=resend>失敗者に再送</button>':''}
    </div>

    <div class=wrap>
      <table>
        <thead>
          <tr>
            <th>対象者</th>
            <th>メール</th>
            <th>状態</th>
            <th>送信日時</th>
            <th>エラー</th>
          </tr>
        </thead>
        <tbody>
          ${
            logs.map(l=>
              `<tr>
                <td>${esc(l.name)}</td>
                <td>${esc(l.email)}</td>
                <td>
                  ${
                    l.status==='failed'
                      ? '<span class="badge closed">送信失敗</span>'
                      : l.status==='answered'
                        ? '<span class="badge pub">回答済み</span>'
                        : '<span class="badge pub">送信済み</span>'
                  }
                </td>
                <td>${esc(l.sentAt||'-')}</td>
                <td>${esc(l.error||'')}</td>
              </tr>`
            ).join('')
            ||
            '<tr><td colspan=5><div class=empty>まだ送信履歴はありません。</div></td></tr>'
          }
        </tbody>
      </table>
    </div>
  </div>`;
}

function statusPage(s){
  const x=sd(s.id)?.stats||{
    responseCount:0,
    sentCount:0,
    responseRate:0,
    unansweredCount:0,
    daily:{},
    recipientStatuses:[]
  };

  const ds=Object.entries(x.daily||{});
  const mx=Math.max(1,...ds.map(a=>Number(a[1])));

  return `<div class=card>
    <div class=bar>
      <h2 style="margin:0">回答状況ダッシュボード</h2>
      <button class=btn data-act=refresh>最新情報に更新</button>
    </div>

    <div class=grid4>
      <div class=stat><span class=small muted>総回答件数</span><b>${x.responseCount}</b></div>
      <div class=stat><span class=small muted>メール送信数</span><b>${x.sentCount}</b></div>
      <div class=stat><span class=small muted>回答率</span><b>${x.responseRate}%</b></div>
      <div class=stat><span class=small muted>未回答者数</span><b>${x.unansweredCount}</b></div>
    </div>
  </div>

  <div class=card>
    <h2 style="margin-top:0">日別回答受付件数</h2>

    ${
      ds.length
        ? ds.map(([d,n])=>
            `<div style="display:grid;grid-template-columns:110px 50px 1fr;gap:8px;align-items:center;margin:8px 0">
              <span class=small>${esc(d)}</span>
              <b>${n}</b>
              <div class=barbg><i style="width:${Math.round(Number(n)/mx*100)}%"></i></div>
            </div>`
          ).join('')
        : '<div class=empty>回答データはまだありません。</div>'
    }
  </div>

  <div class=card>
    <h2 style="margin-top:0">対象者ステータス</h2>

    <div class=wrap>
      <table>
        <thead>
          <tr>
            <th>対象者</th>
            <th>メール</th>
            <th>状態</th>
            <th>送信日時</th>
          </tr>
        </thead>
        <tbody>
          ${
            (x.recipientStatuses||[]).map(r=>
              `<tr>
                <td>${esc(r.name)}</td>
                <td>${esc(r.email)}</td>
                <td>
                  ${
                    r.status==='answered'
                      ? '<span class="badge pub">回答済み</span>'
                      : '<span class="badge info">送信済み・未回答</span>'
                  }
                </td>
                <td>${esc(r.sentAt||'-')}</td>
              </tr>`
            ).join('')
            ||
            '<tr><td colspan=4><div class=empty>対象者データはありません。</div></td></tr>'
          }
        </tbody>
      </table>
    </div>
  </div>`;
}

function resultPage(s){
  const rs=sd(s.id)?.results||[];
  const n=nums(s);

  return `<div class=card>
    <div class=bar>
      <div>
        <h2 style="margin:0">回答結果</h2>
        <div class="small muted">分岐でスキップされた質問は集計母数から除外しています。</div>
      </div>
      <button class=btn data-act=refresh>最新情報に更新</button>
    </div>
  </div>

  ${
    rs.map(r=>{
      const q=qs(s).find(x=>x.id===r.questionId);

      if(r.type==='text'){
        return `<div class=card>
          <h3>${esc(n[r.questionId])} ${esc(q?.text||r.questionId)}</h3>
          <div class=small muted>
            回答対象 ${r.targetCount} 件 / 回答 ${r.answeredCount} 件
          </div>

          ${
            r.texts.length
              ? r.texts.map(t=>
                  `<div style="border:1px solid var(--b);padding:10px;border-radius:7px;margin:7px 0;white-space:pre-wrap">
                    ${esc(t.text)}
                    <div class=small muted>${esc(fmt(t.answeredAt))}</div>
                  </div>`
                ).join('')
              : '<div class=empty>回答はありません。</div>'
          }
        </div>`;
      }

      return `<div class=card>
        <h3>${esc(n[r.questionId])} ${esc(q?.text||r.questionId)}</h3>
        <div class=small muted>回答対象 ${r.targetCount} 件</div>

        ${(r.choices||[]).map(c=>
          `<div style="display:grid;grid-template-columns:180px 55px 1fr 55px;gap:8px;align-items:center;margin:8px 0">
            <span>${esc(c.label)}</span>
            <b>${c.count}</b>
            <div class=barbg>
              <i style="width:${Math.min(100,Number(c.percentage)||0)}%"></i>
            </div>
            <span class=small>${c.percentage}%</span>
          </div>`
        ).join('')}
      </div>`;
    }).join('')
    ||
    '<div class="card"><div class="empty">回答データがまだありません。</div></div>'
  }`;
}

function surveyPage(){
  const s=survey();

  if(!s){
    return surveysPage();
  }

  const tabs=[
    ['content','アンケート内容'],
    ['send','送信'],
    ['status','回答状況'],
    ['result','回答結果']
  ].map(a=>
    `<button class="tab ${st.tab===a[0]?'on':''}" data-tab="${a[0]}">${a[1]}</button>`
  ).join('');

  let b=
    st.tab==='content'
      ?detail(s)
      :st.tab==='send'
        ?sendPage(s)
        :st.tab==='status'
          ?statusPage(s)
          :resultPage(s);

  return shell(
    '',
    'surveys'
  )+
  `<div class=subnav>
    <div class=subnavin>
      <div class=subname>${esc(s.name)}</div>
      ${tabs}
      <button class=btn data-act=back>一覧へ戻る</button>
    </div>
  </div>
  <main class=page>${b}</main>`;
}

function customersPage(){
  const q=st.csearch.toLowerCase();
  const cs=st.customers.filter(
    c=>`${c.name} ${c.email} ${c.company}`.toLowerCase().includes(q)
  );

  return shell(
    `<main class=page>
      <div class=bar>
        <div>
          <h1 class=title>顧客一覧</h1>
          <div class=sub>kintoneから同期した送信対象者を確認します。</div>
        </div>
        <button class="btn primary" data-act=sync>キントーンから最新情報を取得</button>
      </div>

      <div class=card>
        <input id=cmains class=ctl style="max-width:420px" placeholder="氏名・メールアドレス・会社名で検索" value="${esc(st.csearch)}">

        <div class=wrap style="margin-top:10px">
          <table>
            <thead>
              <tr>
                <th>顧客名</th>
                <th>メールアドレス</th>
                <th>会社名</th>
                <th>更新日時</th>
              </tr>
            </thead>
            <tbody>
              ${
                cs.map(c=>
                  `<tr>
                    <td>${esc(c.name)}</td>
                    <td>${esc(c.email)}</td>
                    <td>${esc(c.company)}</td>
                    <td>${esc(fmt(c.updatedAt))}</td>
                  </tr>`
                ).join('')
                ||
                '<tr><td colspan=4><div class=empty>顧客データがありません。</div></td></tr>'
              }
            </tbody>
          </table>
        </div>
      </div>
    </main>`,
    'customers'
  );
}

function settingsPage(){
  const s=st.settings||{smtp:{},kintone:{}};
  const m=s.smtp||{};
  const k=s.kintone||{};

  return shell(
    `<main class=page>
      <div class=bar>
        <div>
          <h1 class=title>設定</h1>
          <div class=sub>SMTPとkintoneの接続設定を管理します。</div>
        </div>

        <button class="btn primary" data-act=savesettings>設定を保存</button>
      </div>

      <div class=card>
        <div class=bar>
          <h2 style="margin:0">SMTP</h2>
          <button class=btn data-act=smtptest>SMTP接続確認</button>
        </div>

        <div class=grid2>
          <div class=fg>
            <label>SMTPサーバ</label>
            <input class=ctl data-set=smtp.host value="${esc(m.host||'')}">
          </div>

          <div class=fg>
            <label>ポート</label>
            <input class=ctl type=number data-set=smtp.port value="${esc(m.port||587)}">
          </div>
        </div>

        <div class=grid2>
          <div class=fg>
            <label>暗号化</label>
            <select class=ctl data-set=smtp.secure>
              <option value=none ${m.secure==='none'?'selected':''}>None</option>
              <option value=ssl ${m.secure==='ssl'?'selected':''}>SSL</option>
              <option value=tls ${m.secure==='tls'?'selected':''}>TLS</option>
            </select>
          </div>

          <div class=fg>
            <label>認証ユーザー名</label>
            <input class=ctl data-set=smtp.username value="${esc(m.username||'')}">
          </div>
        </div>

        <div class=grid2>
          <div class=fg>
            <label>認証パスワード</label>
            <input class=ctl type=password data-set=smtp.password placeholder="変更時のみ入力">
          </div>

          <div class=fg>
            <label>送信元メールアドレス</label>
            <input class=ctl data-set=smtp.fromEmail value="${esc(m.fromEmail||'')}">
          </div>
        </div>

        <div class=fg>
          <label>送信元表示名</label>
          <input class=ctl data-set=smtp.fromName value="${esc(m.fromName||'')}">
        </div>

        <div class=small muted>
          パスワード設定済み：${m.passwordSet?'はい':'いいえ'}
        </div>
      </div>

      <div class=card>
        <div class=bar>
          <h2 style="margin:0">kintone</h2>
          <button class=btn data-act=ktest>kintone接続確認</button>
        </div>

        <div class=grid2>
          <div class=fg>
            <label>サブドメイン</label>
            <input class=ctl data-set=kintone.subdomain value="${esc(k.subdomain||'')}">
          </div>

          <div class=fg>
            <label>アプリID</label>
            <input class=ctl data-set=kintone.appId value="${esc(k.appId||'')}">
          </div>
        </div>

        <div class=grid2>
          <div class=fg>
            <label>ログイン名</label>
            <input class=ctl data-set=kintone.login value="${esc(k.login||'')}">
          </div>

          <div class=fg>
            <label>パスワード</label>
            <input class=ctl type=password data-set=kintone.password placeholder="変更時のみ入力">
          </div>
        </div>

        <div class=grid2>
          <div class=fg>
            <label>顧客名フィールドコード</label>
            <input class=ctl data-set=kintone.nameField value="${esc(k.nameField||'')}">
          </div>

          <div class=fg>
            <label>メールアドレスフィールドコード</label>
            <input class=ctl data-set=kintone.emailField value="${esc(k.emailField||'')}">
          </div>
        </div>

        <div class=grid2>
          <div class=fg>
            <label>プロキシ host:port</label>
            <input class=ctl data-set=kintone.proxyHostPort value="${esc(k.proxyHostPort||'')}">
          </div>

          <div class=fg>
            <label>SSL証明書検証</label>
            <label style="font-weight:400;padding-top:9px">
              <input type=checkbox data-set=kintone.verifySsl ${k.verifySsl?'checked':''}>
              有効にする
            </label>
          </div>
        </div>
      </div>
    </main>`,
    'settings'
  );
}

function render(){
  if(window.APP_MODE==='answer'||window.APP_MODE==='preview'){
    renderRespondent();
    return;
  }

  if(st.page==='editor'){
    app.innerHTML=editorPage();
  }else if(st.page==='survey'){
    app.innerHTML=surveyPage();
  }else if(st.page==='customers'){
    app.innerHTML=customersPage();
  }else if(st.page==='settings'){
    app.innerHTML=settingsPage();
  }else{
    app.innerHTML=surveysPage();
  }

  bind();
}

function renderRespondent(){
  if(!window.APP_CONTEXT){
    app.innerHTML=
      `<div class=respond>
        <div class=respondcard>
          <h1>アンケート</h1>
          <div class="a-ng alert">${esc(window.APP_MESSAGE||'回答画面を表示できません。')}</div>
        </div>
      </div>`;
    return;
  }

  const c=window.APP_CONTEXT;
  const s=c.survey;
  const n=nums(s);
  const preview=!!c.preview;

  app.innerHTML=
    `<div class=respond>
      <div class=respondcard>
        ${
          preview
            ? '<div class="preview">回答者プレビューです。送信しても保存されません。</div>'
            :''
        }

        <h1>${esc(s.name)}</h1>

        <p style="white-space:pre-wrap;color:var(--m)">
          ${esc(s.description||'')}
        </p>

        <form id=af>
          ${
            qs(s).map(q=>{
              if(q.type==='text'){
                return `<section class=rq data-qid="${esc(q.id)}">
                  <div class=rqt>
                    ${esc(n[q.id])} ${esc(q.text)}
                    ${q.required?'<span class=req> *必須</span>':''}
                  </div>
                  <textarea class=ctl name="q_${esc(q.id)}" rows=4></textarea>
                </section>`;
              }

              return `<section class=rq data-qid="${esc(q.id)}">
                <div class=rqt>
                  ${esc(n[q.id])} ${esc(q.text)}
                  ${q.required?'<span class=req> *必須</span>':''}
                </div>

                ${
                  (q.choices||[]).map(x=>
                    q.type==='single'
                      ? `<label class=rchoice>
                          <input type=radio name="q_${esc(q.id)}" value="${esc(x.id)}">
                          <span>${esc(x.label)}</span>
                        </label>`
                      : `<label class=rchoice>
                          <input type=checkbox name="q_${esc(q.id)}" value="${esc(x.id)}">
                          <span>${esc(x.label)}</span>
                        </label>`
                  ).join('')
                }
              </section>`;
            }).join('')
          }

          <div id=ae></div>

          <div style="display:flex;justify-content:flex-end;margin-top:18px">
            <button id=as class="btn primary" ${preview?'disabled':''}>
              ${preview?'プレビュー中':'回答を送信する'}
            </button>
          </div>
        </form>
      </div>
    </div>`;

  bindRespondent();
}

function bindRespondent(){
  const f=document.getElementById('af');

  if(!f){
    return;
  }

  const preview=window.APP_MODE==='preview';

  const update=()=>{
    const s=window.APP_CONTEXT.survey;
    const a={};

    for(const q of qs(s)){
      const name='q_'+q.id;

      if(q.type==='multiple'){
        a[q.id]=[
          ...f.querySelectorAll(
            `input[name="${CSS.escape(name)}"]:checked`
          )
        ].map(x=>x.value);
      }else if(q.type==='single'){
        const x=f.querySelector(
          `input[name="${CSS.escape(name)}"]:checked`
        );

        a[q.id]=x?.value||'';
      }else{
        a[q.id]=
          f.querySelector(
            `[name="${CSS.escape(name)}"]`
          )?.value||'';
      }
    }

    const allow=new Set(
      (()=>{
        const all=qs(s);
        const ix=Object.fromEntries(
          all.map((q,i)=>[q.id,i])
        );

        let i=0;
        let g=0;
        const se=new Set();
        const o=[];

        while(all[i]&&g++<10000){
          const q=all[i];

          if(se.has(q.id)){
            break;
          }

          se.add(q.id);
          o.push(q.id);

          if(q.type!=='single'){
            i++;
            continue;
          }

          const c=(q.choices||[]).find(
            x=>x.id===a[q.id]
          );

          const t=c?(q.branches?.[c.id]||'next'):'next';

          if(t==='end'){
            break;
          }

          if(t==='next'){
            i++;
            continue;
          }

          const id=t.startsWith('question:')
            ?t.slice(9)
            :t;

          if(ix[id]===undefined){
            break;
          }

          i=ix[id];
        }

        return o;
      })()
    );

    f.querySelectorAll('.rq').forEach(
      x=>x.style.display=allow.has(x.dataset.qid)?'':'none'
    );
  };

  f.addEventListener('change',update);
  f.addEventListener('input',update);
  update();

  if(preview){
    return;
  }

  f.addEventListener('submit',async e=>{
    e.preventDefault();

    const b=document.getElementById('as');
    const err=document.getElementById('ae');

    if(b){
      b.disabled=true;
      b.textContent='送信中…';
    }

    const s=window.APP_CONTEXT.survey;
    const a={};

    for(const q of qs(s)){
      const name='q_'+q.id;

      if(q.type==='multiple'){
        a[q.id]=[
          ...f.querySelectorAll(
            `input[name="${CSS.escape(name)}"]:checked`
          )
        ].map(x=>x.value);
      }else if(q.type==='single'){
        const x=f.querySelector(
          `input[name="${CSS.escape(name)}"]:checked`
        );

        if(x){
          a[q.id]=x.value;
        }
      }else{
        const x=f.querySelector(
          `[name="${CSS.escape(name)}"]`
        );

        if(x&&x.value.trim()!==''){
          a[q.id]=x.value;
        }
      }
    }

    try{
      const endpoint=new URL(API);
      endpoint.searchParams.set('api','submit_answer');

      const r=await fetch(
        endpoint.toString(),
        {
          method:'POST',
          headers:{
            'Content-Type':'application/json',
            'Accept':'application/json'
          },
          body:JSON.stringify({
            surveyId:s.id,
            token:window.APP_CONTEXT.token,
            answers:a
          })
        }
      );

      const x=await r.json();

      if(!x.ok){
        const ff=x.error?.fields
          ?Object.values(x.error.fields).join('\n')
          :'';

        throw new Error(
          ff
            ?(x.error?.message||'入力内容を確認してください')+'\n'+ff
            :(x.error?.message||'回答送信に失敗しました。')
        );
      }

      app.innerHTML=
        `<div class=respond>
          <div class=respondcard>
            <div class=done>
              <h1>ご回答ありがとうございました</h1>
              <p>回答を受け付けました。</p>
            </div>
          </div>
        </div>`;
    }catch(x){
      if(err){
        err.innerHTML=
          `<div class="a-ng alert">${esc(x.message)}</div>`;
      }

      if(b){
        b.disabled=false;
        b.textContent='回答を送信する';
      }
    }
  });
}

function bind(){
  document.querySelectorAll('[data-nav]').forEach(x=>
    x.addEventListener('click',()=>{
      const v=x.dataset.nav;

      if(v==='new'){
        st.editor=emptySurvey();
        nav('editor');
      }else{
        nav(v);
      }
    })
  );

  document.querySelectorAll('[data-tab]').forEach(x=>
    x.addEventListener(
      'click',
      ()=>nav('survey',st.sid,x.dataset.tab)
    )
  );

  document.querySelectorAll('[data-act]').forEach(x=>
    x.addEventListener('click',async()=>{
      const a=x.dataset.act;
      const id=x.dataset.id||'';

      if(a==='open'){
        nav('survey',id,'content');
        return;
      }

      if(a==='edit'){
        st.editor=JSON.parse(
          JSON.stringify(survey(id))
        );
        nav('editor');
        return;
      }

      if(a==='back'){
        nav('surveys');
        return;
      }

      if(a==='publicurl'){
        const d=await api(
          'issue_answer_token',
          {surveyId:id},
          x
        );

        if(d){
          confirmBox(
            '回答URL',
            d.url,
            async()=>{},
            '閉じる'
          );
        }

        return;
      }

      if(a==='preview'){
        const endpoint=new URL(API);
        endpoint.searchParams.set(
          'preview',
          id
        );

        location.href=endpoint.toString();
        return;
      }

      if(a==='refresh'){
        if(await reload()){
          render();
        }
        return;
      }

      if(a==='publish'){
        confirmBox(
          'アンケートを公開',
          '内容を確認した上で公開します。',
          async()=>{
            if(await api('publish',{surveyId:id},x)){
              await reload();
              toast('公開しました');
              render();
            }
          },
          '公開する'
        );
        return;
      }

      if(a==='close'){
        confirmBox(
          'アンケートを終了',
          '回答受付を終了します。',
          async()=>{
            if(await api('close',{surveyId:id},x)){
              await reload();
              toast('終了しました');
              render();
            }
          },
          '終了する',
          true
        );
        return;
      }

      if(a==='delete'){
        confirmBox(
          'アンケートを削除',
          '下書きと関連データを削除します。',
          async()=>{
            if(await api('delete_survey',{surveyId:id},x)){
              await reload();
              toast('削除しました');
              nav('surveys');
            }
          },
          '削除する',
          true
        );
        return;
      }

      if(a==='cancel'){
        if(
          st.dirty &&
          !confirm('保存していない変更があります。破棄しますか？')
        ){
          return;
        }

        nav('surveys');
        return;
      }

      if(a==='save'){
        await saveEditor(x);
        return;
      }

      if(a==='addg'){
        syncEditorInputs();

        st.editor.groups.push({
          id:uid('g_'),
          name:'新しいグループ',
          questions:[newQ()]
        });

        st.dirty=true;
        render();
        return;
      }

      const gi=Number(x.dataset.gi);
      const qi=Number(x.dataset.qi);
      const ci=Number(x.dataset.ci);

      if(a==='rmg'){
        syncEditorInputs();

        if(
          st.editor.groups[gi].questions.length &&
          !confirm('このグループの質問も削除されます。よろしいですか？')
        ){
          return;
        }

        st.editor.groups.splice(gi,1);
        st.dirty=true;
        render();
        return;
      }

      if(a==='upg'||a==='downg'){
        syncEditorInputs();

        const to=a==='upg'?gi-1:gi+1;

        if(
          to>=0 &&
          to<st.editor.groups.length
        ){
          [st.editor.groups[gi],st.editor.groups[to]]=
            [st.editor.groups[to],st.editor.groups[gi]];
        }

        st.dirty=true;
        render();
        return;
      }

      if(a==='addq'){
        syncEditorInputs();
        st.editor.groups[gi].questions.push(newQ());
        st.dirty=true;
        render();
        return;
      }

      if(a==='rmq'){
        syncEditorInputs();
        st.editor.groups[gi].questions.splice(qi,1);
        st.dirty=true;
        render();
        return;
      }

      if(a==='upq'||a==='downq'){
        syncEditorInputs();

        const arr=st.editor.groups[gi].questions;
        const to=a==='upq'?qi-1:qi+1;

        if(to>=0&&to<arr.length){
          [arr[qi],arr[to]]=[arr[to],arr[qi]];
        }

        st.dirty=true;
        render();
        return;
      }

      if(a==='addchoice'){
        syncEditorInputs();
        st.editor.groups[gi].questions[qi].choices.push({
          id:uid('c_'),
          label:'新規選択肢'
        });
        st.dirty=true;
        render();
        return;
      }

      if(a==='rmchoice'){
        syncEditorInputs();
        st.editor.groups[gi].questions[qi].choices.splice(ci,1);
        st.dirty=true;
        render();
        return;
      }

      if(a==='all'){
        st.selected=st.customers.map(c=>c.id);
        render();
        return;
      }

      if(a==='none'){
        st.selected=[];
        render();
        return;
      }

      if(a==='customer'){
        const v=id;

        if(st.selected.includes(v)){
          st.selected=st.selected.filter(x=>x!==v);
        }else{
          st.selected.push(v);
        }

        render();
        return;
      }

      if(a==='mailpreview'){
        const sub=document.getElementById('sub');
        const mb=document.getElementById('mb');

        if(sub)st.subject=sub.value;
        if(mb)st.body=mb.value;

        confirmBox(
          '送信プレビュー',
          `対象者数：${st.selected.length}\n\n件名：\n${st.subject}\n\n本文：\n${st.body.replaceAll('{{ANSWER_URL}}','(対象者ごとの回答URL)')}`,
          async()=>{},
          '閉じる'
        );
        return;
      }

      if(a==='send'){
        const sub=document.getElementById('sub');
        const mb=document.getElementById('mb');

        if(sub)st.subject=sub.value;
        if(mb)st.body=mb.value;

        if(!st.selected.length){
          toast('送信対象者を選択してください。',true);
          return;
        }

        confirmBox(
          '送信の最終確認',
          `対象者数：${st.selected.length}\n件名：${st.subject}\n\n送信しますか？`,
          async()=>{
            const d=await api(
              'send_mail',
              {
                surveyId:st.sid,
                customerIds:st.selected,
                subject:st.subject,
                body:st.body
              },
              x
            );

            if(d){
              st.selected=[];
              await reload();
              toast(
                `送信完了：成功 ${d.success} 件 / 失敗 ${d.failed} 件`,
                d.failed>0
              );
              render();
            }
          },
          '送信する'
        );

        return;
      }

      if(a==='resend'){
        const logs=sd(st.sid)?.logs||[];
        const ids=logs
          .filter(l=>l.status==='failed')
          .map(l=>l.id);

        confirmBox(
          '失敗者に再送',
          `${ids.length}件を再送します。`,
          async()=>{
            const d=await api(
              'resend_failed',
              {logIds:ids},
              x
            );

            if(d){
              await reload();
              toast(
                `再送：成功 ${d.success} 件 / 失敗 ${d.failed} 件`,
                d.failed>0
              );
              render();
            }
          },
          '再送する'
        );

        return;
      }

      if(a==='sync'){
        const d=await api(
          'sync_customers',
          {},
          x
        );

        if(d){
          st.customers=d.customers||[];
          toast(`${d.count}件取得しました`);
          render();
        }

        return;
      }

      if(a==='savesettings'){
        const set={
          smtp:{},
          kintone:{}
        };

        document.querySelectorAll('[data-set]').forEach(i=>{
          const [s,k]=i.dataset.set.split('.');

          set[s][k]=
            i.type==='checkbox'
              ?i.checked
              :i.value;
        });

        set.smtp.port=
          Number(set.smtp.port||587);

        const d=await api(
          'save_settings',
          {settings:set},
          x
        );

        if(d){
          st.settings=d.settings;
          toast('設定を保存しました');
          render();
        }

        return;
      }

      if(a==='smtptest'){
        const d=await api(
          'smtp_test',
          {},
          x
        );

        if(d)toast(
          d.message||'SMTP接続成功'
        );

        return;
      }

      if(a==='ktest'){
        const d=await api(
          'kintone_test',
          {},
          x
        );

        if(d)toast(
          d.message||'kintone接続成功'
        );

        return;
      }
    })
  );

  document.querySelectorAll('[data-ed]').forEach(x=>{
    const ev=
      x.tagName==='SELECT'||x.type==='checkbox'
        ?'change'
        :'input';

    x.addEventListener(ev,()=>{
      if(!st.editor)return;

      const k=x.dataset.ed;
      const gi=Number(x.dataset.gi??-1);
      const qi=Number(x.dataset.qi??-1);
      const ci=Number(x.dataset.ci??-1);

      if(k==='name'){
        st.editor.name=x.value;
      }else if(k==='desc'){
        st.editor.description=x.value;
      }else if(k==='start'){
        st.editor.startAt=x.value;
      }else if(k==='end'){
        st.editor.endAt=x.value;
      }else if(k==='num'){
        st.editor.numberingFormat=x.value;
      }else if(k==='gname'){
        st.editor.groups[gi].name=x.value;
      }else if(k==='qtext'){
        st.editor.groups[gi].questions[qi].text=x.value;
      }else if(k==='req'){
        st.editor.groups[gi].questions[qi].required=x.checked;
      }else if(k==='choice'){
        st.editor.groups[gi].questions[qi].choices[ci].label=x.value;
      }else if(k==='branch'){
        const q=st.editor.groups[gi].questions[qi];

        if(x.value==='next'){
          delete q.branches[x.dataset.cid];
        }else{
          q.branches[x.dataset.cid]=x.value;
        }
      }else if(k==='type'){
        const q=st.editor.groups[gi].questions[qi];

        q.type=x.value;

        if(x.value==='text'){
          q.choices=[];
          q.branches={};
        }else{
          q.branches={};

          if(!q.choices.length){
            q.choices=[
              {id:uid('c_'),label:'選択肢1'},
              {id:uid('c_'),label:'選択肢2'}
            ];
          }
        }

        st.dirty=true;
        render();
        return;
      }

      st.dirty=true;
    });
  });

  const cs=document.getElementById('cs');
  if(cs){
    cs.addEventListener(
      'input',
      ()=>{
        st.csearch=cs.value;
        render();
      }
    );
  }

  const cms=document.getElementById('cmains');

  if(cms){
    cms.addEventListener(
      'input',
      ()=>{
        st.csearch=cms.value;
        render();
      }
    );
  }

  const groups=document.getElementById('groups');

  if(groups){
    let dg=null;
    let dq=null;

    groups.querySelectorAll('[data-dg]').forEach(e=>{
      e.addEventListener(
        'dragstart',
        ()=>dg=Number(e.dataset.gi)
      );

      e.addEventListener(
        'dragover',
        ev=>ev.preventDefault()
      );

      e.addEventListener(
        'drop',
        ev=>{
          ev.preventDefault();

          const to=Number(e.dataset.gi);

          if(dg===null||dg===to){
            return;
          }

          syncEditorInputs();

          const g=st.editor.groups.splice(dg,1)[0];

          st.editor.groups.splice(to,0,g);

          st.dirty=true;
          render();
        }
      );
    });

    groups.querySelectorAll('[data-dq]').forEach(e=>{
      e.addEventListener(
        'dragstart',
        ()=>dq={
          gi:Number(e.dataset.gi),
          qi:Number(e.dataset.qi)
        }
      );

      e.addEventListener(
        'dragover',
        ev=>ev.preventDefault()
      );

      e.addEventListener(
        'drop',
        ev=>{
          ev.preventDefault();

          if(!dq)return;

          const tg=Number(e.dataset.gi);
          const tq=Number(e.dataset.qi);

          syncEditorInputs();

          const q=st.editor.groups[dq.gi].questions.splice(
            dq.qi,
            1
          )[0];

          let pos=tq;

          if(dq.gi===tg&&dq.qi<tq){
            pos--;
          }

          st.editor.groups[tg].questions.splice(
            Math.max(0,pos),
            0,
            q
          );

          dq=null;
          st.dirty=true;
          render();
        }
      );
    });
  }
}

function newQ(){
  return {
    id:uid('q_'),
    text:'',
    type:'single',
    required:true,
    choices:[
      {id:uid('c_'),label:'選択肢1'},
      {id:uid('c_'),label:'選択肢2'}
    ],
    branches:{}
  };
}

function syncEditorInputs(){
  if(!st.editor)return;

  const g=[
    ...document.querySelectorAll('[data-ed]')
  ];

  for(const x of g){
    const k=x.dataset.ed;
    const gi=Number(x.dataset.gi??-1);
    const qi=Number(x.dataset.qi??-1);
    const ci=Number(x.dataset.ci??-1);

    if(k==='name'){
      st.editor.name=x.value;
    }else if(k==='desc'){
      st.editor.description=x.value;
    }else if(k==='start'){
      st.editor.startAt=x.value;
    }else if(k==='end'){
      st.editor.endAt=x.value;
    }else if(k==='num'){
      st.editor.numberingFormat=x.value;
    }else if(k==='gname'){
      st.editor.groups[gi].name=x.value;
    }else if(k==='qtext'){
      st.editor.groups[gi].questions[qi].text=x.value;
    }else if(k==='req'){
      st.editor.groups[gi].questions[qi].required=x.checked;
    }else if(k==='choice'){
      st.editor.groups[gi].questions[qi].choices[ci].label=x.value;
    }else if(k==='branch'){
      const q=st.editor.groups[gi].questions[qi];

      if(x.value==='next'){
        delete q.branches[x.dataset.cid];
      }else{
        q.branches[x.dataset.cid]=x.value;
      }
    }
  }
}

async function saveEditor(btn){
  syncEditorInputs();

  if(!st.editor.name.trim()){
    toast('アンケート名は必須です。',true);
    return;
  }

  const d=await api(
    'save_survey',
    {survey:st.editor},
    btn
  );

  if(!d){
    return;
  }

  await reload();

  st.dirty=false;
  st.editor=null;

  toast('アンケートを保存しました');
  nav('survey',d.survey.id,'content');
}

async function init(){
  if(
    window.APP_MODE==='answer'||
    window.APP_MODE==='preview'
  ){
    renderRespondent();
    return;
  }

  if(fileMode){
    app.innerHTML=
      '<div class=respond><div class=respondcard>' +
      '<h1>Apache経由で開いてください</h1>' +
      '<div class="a-info alert">' +
      'index.phpをブラウザから直接開かず、Apacheで公開されているURLから開いてください。' +
      '</div></div></div>';

    return;
  }

  if(!await reload()){
    app.innerHTML=
      '<div class=page><div class="a-ng alert">' +
      '初期データを取得できませんでした。' +
      '</div></div>';

    return;
  }

  const p=new URLSearchParams(location.search);

  if(p.get('page')==='editor'){
    st.page='editor';
    st.editor=emptySurvey();
  }else if(
    p.get('page')==='survey'&&
    p.get('id')
  ){
    st.page='survey';
    st.sid=p.get('id');
    st.tab=p.get('tab')||'content';
  }else{
    st.page=p.get('page')||'surveys';
  }

  render();
}

window.addEventListener('beforeunload',e=>{
  if(
    window.APP_MODE==='admin'&&
    st.dirty
  ){
    e.preventDefault();
    e.returnValue='';
  }
});

init();
});
</script>
</body>
</html>
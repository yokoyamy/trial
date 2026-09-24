データ仕様
顧客情報
data/customers.json

メール・キントーン設定
data/settings.json

アンケート情報
surveys.json


---

index.phpを再作成するとき、

localStorage に保存している旧版のアプリデータは引き継がない。

新しい index.php を初回表示した際、旧版の保存データを検出したら破棄する。

特に、CSRFトークンを localStorage に保存しない。CSRFトークンはサーバー側セッションを正とする。

メール設定など、サーバー側のJSONに保存済みの正式な設定は、localStorage ではなくサーバー側のデータを読み込む。

localStorage を使う必要がある画面状態については、アプリのバージョン識別子を持たせる。

index.php を差し替えてバージョンが変わったら、旧バージョンの localStorage をクリアして、新しい状態から開始する。

ただし、サーバー側の data/settings.json に保存済みのメール設定まで消してはいけない。

---


PHP + HTML + CSS + JavaScript を全部 index.php 1ファイルにまとめる前提でも、かなりの行数を出せます。

目安としては：

500〜1,000行：余裕

1,000〜3,000行：問題なく対応可能

3,000〜5,000行：可能。ただし一度に出すより分割推奨

5,000〜10,000行以上：可能な場合もありますが、1回で全部出すと途中で切れるリスクが高い

10,000行超：複数回に分けて作るのが安全

特に index.php 1ファイルなら、例えば、

<?php
// PHP
?>

<!DOCTYPE html>
<html>
<head>
    <style>
        /* CSS */
    </style>
</head>
<body>

    <!-- HTML -->

    <script>
        // JavaScript
    </script>
</body>
</html>

という構成で、数千行規模の完成品も作れます。

もし「かなり大規模なWebアプリを1枚の index.php に全部入れたい」という話なら、最初から3,000〜5,000行程度を想定して設計して、必要なら続きを追加していくのが現実的です。

なので、"</head>"までと、それ以降に分割して出す。
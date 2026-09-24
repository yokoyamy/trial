# アンケート業務運営アプリ 実装要件

## 1. 基本方針

本アプリは、参照業務要件およびモックの画面・操作を実際に動作させるための本番実装とする。

### 1.1 動作環境

- Apache 2.4
- PHP 8.4 / 8.5
- Windows / Unix
- データベースは使用しない
- PHP / HTML / CSS / JavaScript は `index.php` 1ファイルに格納する
- 文字コードは UTF-8 とする
- 外部ライブラリへの依存は原則として設けない
- PHPからのHTTP通信にcURLは使用しない
- kintoneとの通信は `stream_context_create` および `file_get_contents` を使用する

### 1.2 データ保存

アプリケーションルート配下の `data` ディレクトリにJSON形式で保存する。

使用するファイルは以下とする。

- `data/settings.json`
  - メール送信設定
  - kintone設定
- `data/customers.json`
  - 顧客情報のキャッシュまたはアプリ内部で利用する顧客情報
- `data/surveys.json`
  - アンケート情報
- 回答情報、送信履歴等を保存する場合は、業務要件に対応するJSONファイルを追加する

JSONファイルは直接上書きするのではなく、一時ファイルへの書き込み後に置き換える方式とし、保存途中の内容でファイルが壊れないようにする。

JSON保存時はUTF-8で保存する。

---

# 2. データ仕様

## 2.1 settings.json

settings.jsonは以下の構造を基本とする。

```text
{
  "mail": {
    "smtp": "",
    "port": "",
    "security": "",
    "username": "",
    "password": "",
    "from": "",
    "fromName": "",
    "ready": false
  },
  "kintone": {
    "domain": "",
    "appId": "",
    "loginName": "",
    "password": "",
    "proxyHost": "",
    "proxyPort": "",
    "proxyAuth": false,
    "sslVerify": false,
    "ready": false
  }
}

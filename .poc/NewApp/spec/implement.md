# アンケート管理システム
# 実装要件 総差し替え版

---

# 1. 基本方針

本システムは、アンケートの作成、編集、公開、回答依頼、回答、回答状況確認、回答結果、集計、顧客管理、SMTP設定、kintone連携を一体で管理するWebアプリケーションとする。

実装は以下の4つを常に一致させる。

- 業務要件
- 操作要件
- 参照モック
- 本実装

画面上で可能な操作と、サーバー側で可能な操作を一致させる。

画面側だけで業務ルールを成立させてはならない。

重要な業務判断は必ずサーバー側で再検証する。

同じデータを複数の場所に別々の正本として保持してはならない。

特に設定情報については、保存先と読み込み元を統一する。

画面を再読み込みした場合でも、保存済みデータが同じ状態で復元されることを基本要件とする。

---

# 2. 実行環境

- Apache 2.4
- PHP 8.4 / 8.5
- データベースは使用しない
- データはアプリケーションルート配下のJSONファイルで管理する
- PHP / HTML / CSS / JavaScriptは1つのindex.phpにまとめる
- 画面とAPIの入口は同一のindex.phpとする
- 外部HTTP通信はPHP標準機能を使用する
- kintone通信にcURLを使用しない
- CORSによる回避を行わない
- UTF-8を使用する
- PHP 8.4 / 8.5で動作すること

PHPのHTTPレスポンスヘッダー取得では、$http_response_headerを直接参照しない。

HTTPレスポンスヘッダー取得は安全な共通処理を経由する。

曖昧な関数定義、実装時の都合による未定義のフォールバック処理を設けない。

---

# 3. データファイル構成

アプリケーションルートに以下のJSONファイルを使用する。

- surveys.json
- customers.json
- responses.json
- answer_tokens.json
- send_logs.json
- settings.json
- kintone_mapping.json
- kintone_sync_logs.json
- audit_logs.json

各ファイルには役割を明確に持たせる。

## surveys.json

アンケート本体を保存する。

## customers.json

通常回答者として利用する顧客情報を保存する。

## responses.json

回答済みアンケートの回答内容を保存する。

## answer_tokens.json

通常回答者および個別回答者の回答URLトークンと利用状態を保存する。

## send_logs.json

回答依頼メールの送信状態、送信結果、エラー情報を保存する。

## settings.json

SMTP、kintoneなどアプリケーション設定の唯一の正本とする。

## kintone_mapping.json

kintoneフィールド定義、schemaHash、マッピング情報を保存する。

## kintone_sync_logs.json

kintone同期履歴を保存する。

## audit_logs.json

重要操作の履歴を保存する。

---

# 4. データの正本と読み込み

各データについて、保存先を唯一の正本とする。

特に設定情報については、

- settings.json

を唯一の正本とする。

`app_settings.json`など、同じ設定用途の別ファイルを正式なデータ保存先として使用してはならない。

画面上のJavaScript変数を正本として扱ってはならない。

画面を再読み込みした場合は、JSONファイルから現在値を読み込んで画面を構築する。

設定を変更していないのに、画面再読み込みによって初期値へ戻ってはならない。

---

# 5. JSONデータの読み込み

JSONファイルの読み込みでは以下を区別する。

- ファイルが存在しない
- ファイルが読み込めない
- JSON形式が壊れている
- JSON構造が不正
- 必須項目が不足
- 正常に読み込めた

JSON読み込みに失敗した場合、空データとして扱ってはならない。

JSON読み込みに失敗した場合、初期値で既存ファイルを上書きしてはならない。

JSONが壊れている場合、利用者へ読み込みエラーを表示する。

正常な既存データが存在する場合、それを読み込んで画面へ反映する。

---

# 6. JSONデータの保存

JSON保存では既存データを破壊しない。

基本的には、

1. 現在のJSONを読み込む
2. 読み込み結果を検証する
3. 変更対象だけを更新する
4. 全体構造を検証する
5. 一時ファイルへ保存する
6. 保存結果を確認する
7. 正常終了した場合のみ本ファイルへ反映する

という順序とする。

保存途中のエラーによって既存ファイルを空ファイルや不完全なJSONにしてはならない。

---

# 7. JSONファイルの初回作成

ファイルが存在しない場合と、ファイルが壊れている場合を同じ扱いにしてはならない。

初回起動時に存在しないデータファイルについては、定義済みの空構造を作成してよい。

ただし、サンプルデータを本番データとして自動投入してはならない。

settings.jsonが存在しない場合は「未設定」として扱う。

settings.jsonが存在するが読み込めない場合は「設定読み込みエラー」として扱う。

---

# 8. セッション・CSRF・セキュリティ

セッションを利用する。

セッションCookieはHttpOnlyを有効にする。

SameSiteはLaxとする。

CSRFトークンをセッション内に保持する。

状態変更を伴うPOST処理ではCSRF検証を必須とする。

セッション情報はアプリ固有のキー配下に保存する。

他アプリと同名の汎用的なセッションキーを直接使用しない。

HTMLへ出力する動的文字列は必ず安全にエスケープする。

JavaScriptで動的文字列を表示する場合はtextContentを基本とする。

パスワード、認証情報、秘密情報をログへ出力しない。

kintone認証情報を画面やAPIレスポンスへ出力しない。

サーバー内部の物理パスを利用者へ表示しない。

---

# 9. HTTPヘッダーとAPI応答

セキュリティ対策として以下を設定する。

- X-Frame-Options: SAMEORIGIN
- X-Content-Type-Options: nosniff

JSON APIのレスポンスは以下の形式を基本とする。

成功:

{
  "ok": true,
  "data": {}
}

失敗:

{
  "ok": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "利用者向けメッセージ",
    "fields": {}
  }
}

JSON APIを返す場合は不要なHTML出力を混在させない。

APIエラーコードそのものを利用者向け文章として表示しない。

---

# 10. アンケートデータ

アンケートは最低限以下を保持する。

- id
- name
- description
- status
- startAt
- endAt
- numberingFormat
- groups
- createdAt
- updatedAt

statusは以下のみとする。

- draft
- published
- closed

idはアンケート内で一意とする。

公開済みアンケートについては、既存回答との整合性を壊す変更を無制限に許可しない。

---

# 11. グループ

グループについて以下を提供する。

- 追加
- 編集
- 削除
- 並べ替え

グループIDはアンケート内で一意とする。

削除したグループを質問や分岐が参照している状態を残してはならない。

質問を含むグループを削除する場合は、削除対象を明確に確認する。

---

# 12. 質問

質問について以下を提供する。

- 追加
- 編集
- 削除
- 並べ替え
- グループ間移動

質問タイプは以下とする。

- text
- single
- multiple

質問IDはアンケート内で一意とする。

質問の並び順は保存値を正本とする。

質問番号は保存されているグループ・質問順序から一貫して表示する。

---

# 13. 選択肢

singleおよびmultiple質問には選択肢を設定できる。

選択肢には以下を保持する。

- choiceId
- label

choiceIdは質問内で一意とする。

空の選択肢を登録してはならない。

重複choiceIdを登録してはならない。

削除したchoiceIdを分岐先として残してはならない。

既存回答で利用されている選択肢を削除・変更する場合は、過去回答との整合性を壊さない。

---

# 14. 分岐

分岐を設定できるのはsingle質問のみとする。

textおよびmultiple質問には分岐を設定しない。

分岐先は以下のみ許可する。

- next
- question:<questionId>
- end

サーバー側で以下を検証する。

- 対象質問が存在する
- 対象質問が同一アンケートに属する
- 削除済み質問を参照していない
- choiceIdが存在する
- 自分自身を参照していない
- 循環がない
- end後に別質問へ進まない

公開時にも再検証する。

---

# 15. 質問の並び替えと分岐整合性

質問の移動時には以下を考慮する。

- 同一グループ内移動
- グループ間移動
- 空グループへの移動
- 質問番号の再計算
- 分岐先表示の更新

質問を移動しても分岐先ID自体は勝手に別質問へ変更しない。

削除・移動によって不正な分岐が発生した場合は保存または公開を拒否する。

---

# 16. アンケート期間

startAtおよびendAtはサーバー側で検証する。

以下を検証する。

- 日付形式
- startAt <= endAt
- 公開状態との整合
- 現在時刻との関係
- 回答期間内かどうか

公開前に不正な期間を検出する。

回答受付時にもサーバー側で現在時刻を確認する。

画面表示だけで回答可否を判断してはならない。

---

# 17. 顧客データ

customers.jsonには最低限以下を保持する。

- customerId
- name
- organization
- department
- email
- phone
- address
- status
- createdAt
- updatedAt
- kintoneRecordId
- kintoneUpdatedAt
- syncStatus
- syncError

通常回答者はcustomers.jsonの顧客を基準とする。

顧客削除によって過去の送信履歴や回答結果を参照不能にしてはならない。

---

# 18. kintone接続設定

settings.jsonに以下を保持する。

- subdomain
- appId
- login
- password
- proxyHostPort
- verifySsl

kintone認証はX-Cybozu-Authorizationを使用する。

loginとpasswordはtrimしてから認証情報を生成する。

APIトークンは使用しない。

認証情報の実値は画面、APIレスポンス、ログへ出力しない。

GETではJSON本文を送信しない。

クエリパラメータはRFC3986形式でエンコードする。

POSTおよびPUTはJSONで送信する。

proxyHostPortが設定されている場合はstream_context_createへproxyを設定する。

プロキシ利用時はrequest_fulluriをtrueとする。

SSL証明書検証は設定値に従う。

現行要件ではデフォルトを検証OFFとする。

---

# 19. kintone URLと通信

kintoneドメイン入力について、以下を許容する。

- example
- example.cybozu.com
- https://example.cybozu.com

内部では重複したhttpsやcybozu.comを生成しない。

kintone URL生成処理を共通化する。

通信方式はPHP標準のstream_context_createおよびfile_get_contentsを使用する。

cURLは使用しない。

---

# 20. kintone接続確認

接続確認では単なる通信確認だけでなく、対象アプリを利用可能であることまで確認する。

確認内容:

- 接続先
- 認証
- appId
- アプリ存在
- アクセス権

エラーを以下のように区別する。

- 接続先不正
- 認証失敗
- appId不正
- 権限不足
- 通信失敗
- APIエラー

結果は通常画面へ表示する。

X-Cybozu-Authorizationの実値を表示してはならない。

---

# 21. kintoneフィールド定義取得

対象アプリの最新フィールド定義を取得する。

使用API:

GET /k/v1/app/form/fields.json

取得情報には最低限以下を含める。

- field code
- field type
- field label
- required
- writable
- 選択肢
- 同期判断に必要な設定

field labelは画面表示用とする。

内部的な識別子にはfield codeを使用する。

---

# 22. kintoneマッピング

本システムの顧客項目とkintone field codeを対応付ける。

マッピング対象例:

- name
- organization
- department
- email
- phone
- address

住所については、kintone側で複数フィールドへ分割されている場合に対応できるようにする。

画面ではfield labelを表示する。

内部にはfield codeを保存する。

マッピングには対象appIdを保存する。

異なるappIdのmappingを利用してはならない。

---

# 23. kintoneフィールド定義保存

kintone_mapping.jsonには最低限以下を保持する。

- appId
- fetchedAt
- schemaHash
- fields
- mappings
- syncKey
- mappingVersion
- updatedAt

schemaHashはフィールド定義から決定的に生成する。

少なくとも以下をハッシュ対象とする。

- field code
- field type
- required
- writable
- 選択肢
- 同期判断に必要な設定

必要に応じてfield labelも対象とする。

---

# 24. kintoneマッピング検証

マッピング保存前にサーバー側で検証する。

検証内容:

- field code存在
- appId一致
- field type一致
- writableか
- required条件
- 必須マッピング
- 不正な重複割当
- 未対応field type
- 値変換可能性
- mapping構造

画面側だけで検証を完結させない。

保存直前にも再検証する。

---

# 25. 自動マッピング

自動マッピングを行う場合でも、最終確定は利用者が行う。

自動推測だけで同期を開始してはならない。

候補判定は以下を優先する。

1. 完全一致するfield code
2. 保存済みの明示的mapping
3. 候補表示

field label一致だけで確定してはならない。

利用者の確認なしに別フィールドへ自動変更してはならない。

---

# 26. kintone同期

kintone顧客アプリは外部顧客マスタとして扱う。

同期手順を以下に固定する。

1. kintone設定確認
2. 対象アプリ確認
3. 最新フィールド定義取得
4. フィールド定義確認
5. マッピング確認
6. サーバー側マッピング検証
7. マッピング保存
8. schemaHash確認
9. 同期前プレビュー
10. 同期実行
11. 同期結果保存

フィールド定義を取得せずに同期してはならない。

保存済みの古いmappingだけで同期してはならない。

---

# 27. kintoneレコード取得

大量データを前提とする。

1回のAPI取得結果だけで全顧客と判断してはならない。

取得件数に応じて分割取得する。

10,000件を超える場合はカーソル方式を使用する。

取得途中でエラーが発生した場合は同期全体を成功扱いにしない。

取得対象は同期に必要なfield codeを中心とする。

---

# 28. kintone同一性

顧客同一性はkintoneRecordIdを基準とする。

新規:

- kintoneRecordIdが存在しない

更新:

- kintoneRecordIdが存在する

メールアドレスだけで別顧客を統合しない。

同じメールアドレスを持つ複数kintoneRecordIdを自動統合しない。

メールアドレス変更後もkintoneRecordIdが同じなら同一顧客とする。

---

# 29. kintone削除・非アクティブ

kintone側から顧客が削除されても、本システムの顧客を履歴ごと物理削除しない。

過去の以下の情報を保持する。

- 回答依頼
- 送信履歴
- 回答
- 集計
- kintone同期履歴

必要に応じて非アクティブ状態として管理する。

---

# 30. kintone同期プレビュー

同期前に以下を表示する。

- 対象件数
- 新規
- 更新
- 変更なし
- 対象外
- エラー
- マッピングエラー
- 値変換エラー
- 必須値不足

重大なエラーがある場合は同期実行できない。

プレビューではcustomers.jsonを確定更新しない。

プレビュー後にkintone構成が変わった場合は、再確認を要求する。

---

# 31. kintone同期変更検知

同期開始直前に最新フィールド定義を取得する。

以下の場合は同期を中止する。

- appIdが違う
- field codeが存在しない
- field typeが変わった
- writable状態が変わった
- required状態が変わった
- schemaHashが変わった
- 必須mappingが成立しない

schemaHash変更後に旧mappingをそのまま利用してはならない。

別fieldへの自動付け替えを行わない。

---

# 32. kintone同期内容

マッピングされた項目だけを更新する。

マッピングされていない本システム項目は保持する。

空値を無条件に「変更なし」と扱わない。

業務上空値への更新が必要な場合は明示的に反映する。

メールアドレスなど形式検証が必要な値は同期時にも検証する。

---

# 33. kintone同期失敗・排他制御

同期中の二重実行を防止する。

複数ブラウザから同時に同期を開始しても、同一同期を二重実行できないようにする。

同期中は実行中状態を表示する。

同期途中で重大なエラーが発生した場合は全体成功としない。

既存customers.jsonを破壊しない。

同期結果とエラー概要を記録する。

---

# 34. SMTP設定

settings.jsonに以下を保持する。

- host
- port
- encryption
- username
- password
- fromEmail
- fromName

encryptionは以下とする。

- none
- ssl
- tls

設定画面を開いた際にはsettings.jsonの保存済み値を読み込む。

画面へ表示する値は保存済み設定を正本とする。

保存済みパスワードの実値は画面へ返さない。

---

# 35. 設定データの保存・再読み込み

設定を保存するときは、settings.json全体を読み込んだうえで変更対象だけを更新する。

SMTPだけを変更してもkintone設定を消してはならない。

kintoneだけを変更してもSMTP設定を消してはならない。

フィールド関連設定を変更してもSMTP設定を消してはならない。

設定保存後に画面を再読み込みしても、保存値が表示されること。

設定保存成功をサーバー側で確認してから「保存しました」と表示する。

---

# 36. 設定データAPI

最低限以下のAPIを提供する。

GET:

- api=settings_get

POST:

- api=settings_save

settings_getでは秘密情報を返さない。

以下は状態のみ返す。

- smtpPasswordConfigured
- kintonePasswordConfigured

settings_saveでは変更対象だけを更新する。

パスワード項目が未変更の場合は既存値を維持する。

空欄だからといって既存パスワードを勝手に削除してはならない。

---

# 37. 設定ファイル移行

過去の実装でapp_settings.jsonが存在する場合を考慮する。

正式な正本はsettings.jsonとする。

settings.jsonが存在せず、app_settings.jsonが存在する場合は、内容を検証してsettings.jsonへ移行できるようにする。

移行成功後も旧ファイルを勝手に削除しない。

移行結果を画面へ表示する。

settings.jsonとapp_settings.jsonの両方を毎回別々の正本として読み込んではならない。

---

# 38. 回答トークン

answer_tokens.jsonに以下を保持する。

- tokenId
- surveyId
- respondentType
- customerId
- organization
- department
- email
- status
- issuedAt
- infoEnteredAt
- answeredAt
- expiresAt

respondentType:

- customer
- individual

status:

- unused
- info_entered
- answered
- invalid

トークンは十分なランダム性を持つ値とする。

同じtokenIdを複数アンケートで使用しない。

---

# 39. 通常回答者

通常回答者はcustomers.jsonの顧客を基準とする。

回答依頼時には対象アンケートと対象顧客を明確に関連付ける。

同じアンケートについて同一顧客へ意図しない二重送信を防止する。

回答URLは対象者ごとに個別トークンを発行する。

顧客情報が変更されても、過去の回答結果は過去時点の回答者情報を参照できるようにする。

---

# 40. 個別回答者

顧客一覧に登録されていない回答者についても、個別回答URLを発行できる。

個別回答者はURLアクセス後、以下を入力する。

- 組織名
- 部署名
- メールアドレス

入力前の状態:

- 未使用

情報入力後:

- 回答者情報入力済み

回答完了後:

- 回答済み

個別回答者をcustomers.jsonへ自動登録してはならない。

必要な場合は別途明示的な顧客登録操作とする。

---

# 41. 回答画面

回答画面は管理画面から独立する。

回答者URLにアクセスした際にトークンを検証する。

検証内容:

- token存在
- survey存在
- survey status
- 回答期間
- token状態
- tokenとsurveyの一致

管理画面への戻る導線を回答者画面へ設けない。

個別回答者の場合は、回答開始前に回答者情報を入力・確認できる。

---

# 42. 回答登録

回答登録時にサーバー側で必ず検証する。

- surveyId
- token
- token状態
- survey状態
- 回答期間
- 必須回答
- 到達可能性
- 選択肢
- text最大文字数
- single選択数
- multiple選択数
- 重複選択
- 回答構造

ブラウザ側の検証だけを信用しない。

---

# 43. 分岐と回答

分岐によって到達しなかった質問はスキップ扱いとする。

未到達質問を必須未回答として扱わない。

未到達質問を集計上の未回答として扱わない。

ブラウザから送信された回答経路をそのまま信用しない。

サーバー側で回答経路を再計算する。

不正な経路の回答を拒否する。

---

# 44. 回答保存と二重回答防止

responses.jsonに回答を保存する。

回答には最低限以下を保持する。

- responseId
- surveyId
- tokenId
- respondentType
- customerId
- organization
- department
- email
- startedAt
- answeredAt
- answers

同一tokenで二重回答できないようにする。

トークン使用済み更新と回答保存の不整合を防止する。

以下の状態を成功として残してはならない。

- tokenだけ使用済み
- 回答だけ保存
- 回答済みだが回答データが存在しない

---

# 45. 送信ログ

send_logs.jsonにはメール送信状態を保存する。

最低限以下を保持する。

- sendId
- surveyId
- tokenId
- respondentType
- customerId
- recipientEmail
- sentAt
- status
- error
- retryCount

status例:

- pending
- sending
- sent
- failed
- cancelled

パスワードや認証情報を保存しない。

同一対象への二重送信を防止する。

失敗した対象は再送できる。

---

# 46. SMTP接続確認・テストメール

SMTP接続確認を実行できる。

接続確認結果を通常画面へ表示する。

テストメール送信先を入力して実際のテスト送信を行える。

テストメールと本番の回答依頼メールを区別する。

SMTPエラーを利用者へ分かる範囲で表示する。

パスワードや認証情報をエラー表示へ含めない。

---

# 47. 回答依頼メール

公開済みアンケートについて回答依頼を送信できる。

送信前に以下を検証する。

- アンケート存在
- published状態
- 回答期間
- 対象者
- メールアドレス
- トークン
- 既送信状態
- SMTP設定

回答URLは対象者ごとに個別URLとする。

送信成功後に送信済み状態へ変更する。

送信失敗はfailedとして記録する。

失敗対象だけ再送できる。

---

# 48. 回答状況・回答結果

回答状況では最低限以下を表示する。

- 送信対象数
- 送信済み
- 配信成功
- 配信失敗
- 回答済み
- 未回答
- 回答率

回答者単位で以下を確認できる。

- 通常回答者 / 個別回答者
- 組織名
- 部署名
- メールアドレス
- 回答状態
- 送信日時
- 回答日時
- エラー

通常回答者と個別回答者を同じ回答状況・回答結果画面で扱えるようにする。

---

# 49. 集計

回答結果を質問単位で集計する。

選択式質問では選択肢ごとの回答数を表示する。

複数選択では選択肢ごとの選択数を集計する。

text質問では回答内容を確認できる。

分岐によって未到達となった質問は集計対象から除外する。

未回答は「回答経路上で到達したが回答されなかった質問」とする。

---

# 50. API仕様

最低限以下を提供する。

## GET

- api=state
- api=survey_list
- api=survey_get
- api=customer_list
- api=response_status
- api=response_list
- api=stats
- api=settings_get
- api=kintone_mapping_get

## POST

- api=survey_save
- api=survey_delete
- api=survey_publish
- api=survey_close
- api=group_save
- api=group_delete
- api=group_reorder
- api=question_save
- api=question_delete
- api=question_reorder
- api=customer_save
- api=customer_delete
- api=issue_token
- api=individual_token_issue
- api=respondent_info_save
- api=response_save
- api=send_mail
- api=resend_failed
- api=smtp_test
- api=settings_save
- api=kintone_test
- api=kintone_fields
- api=kintone_mapping_validate
- api=kintone_mapping_save
- api=kintone_sync_preview
- api=kintone_sync_execute

状態変更APIにはCSRF検証を行う。

---

# 51. バリデーション・エラー・UI制御

すべての入力値はサーバー側で検証する。

検証内容:

- 必須
- 型
- 文字数
- 配列構造
- ID存在
- ID所属
- 重複
- 日付
- メール形式
- 状態
- 権限
- CSRF
- JSON構造

最低限以下のエラーコードを使用する。

- VALIDATION_ERROR
- CSRF_ERROR
- AUTH_ERROR
- NOT_FOUND
- JSON_ERROR
- JSON_SCHEMA_ERROR
- FILE_LOCK_ERROR
- FILE_WRITE_ERROR
- SETTINGS_NOT_FOUND
- SETTINGS_READ_ERROR
- SETTINGS_WRITE_ERROR
- SURVEY_ERROR
- SURVEY_PUBLISH_ERROR
- TOKEN_ERROR
- TOKEN_ALREADY_USED
- RESPONSE_ERROR
- RESPONSE_ALREADY_EXISTS
- SMTP_ERROR
- SMTP_CONFIG_ERROR
- MAIL_SEND_ERROR
- KINTONE_ERROR
- KINTONE_AUTH_ERROR
- KINTONE_APP_ERROR
- KINTONE_FIELD_ERROR
- KINTONE_SCHEMA_CHANGED
- KINTONE_MAPPING_INVALID
- KINTONE_SYNC_ERROR
- KINTONE_SYNC_LOCKED
- KINTONE_SYNC_CONFLICT

エラーは通常画面へ表示する。

ブラウザコンソールだけにエラーを出してはならない。

---

# 52. UI・非同期処理・画面遷移

JavaScriptの処理はDOM構築完了後に実行する。

イベント登録時には対象要素の存在確認を行う。

対象要素が存在しなくても後続処理を停止させない。

非同期処理を開始するボタンは、処理開始直後にdisabled=trueとする。

同時にローディング表示を行う。

処理終了時には成功・失敗にかかわらずボタンを復元する。

同じ処理を二重実行できないようにする。

成功メッセージはサーバー側の成功を確認してから表示する。

画面再読み込み後も保存データから同じ状態を再構築する。

特に設定画面については、画面内に固定した初期値を保存値より優先してはならない。

---

# 53. ファイル更新整合性・監査

以下のファイルは書き込み途中の破損を防止する。

- surveys.json
- customers.json
- responses.json
- answer_tokens.json
- send_logs.json
- settings.json
- kintone_mapping.json
- kintone_sync_logs.json
- audit_logs.json

重要操作をaudit_logs.jsonへ記録する。

対象:

- アンケート公開
- アンケート終了
- アンケート削除
- メール送信
- メール再送
- SMTP設定変更
- kintone設定変更
- kintoneフィールド定義取得
- マッピング変更
- kintone同期
- 同期失敗

秘密情報を監査ログへ保存しない。

必要以上の個人情報を監査ログへ保存しない。

---

# 54. 受入条件・禁止事項・実装完了条件

## 54-1. アンケート

以下を実行できること。

- 作成
- 編集
- 削除
- グループ追加
- グループ編集
- グループ削除
- グループ並べ替え
- 質問追加
- 質問編集
- 質問削除
- 質問並べ替え
- 質問グループ間移動
- 選択肢管理
- 分岐設定
- 分岐エラー検出
- 循環分岐検出
- 公開
- 終了
- 回答期間管理

公開APIでも再検証されること。

---

## 54-2. 設定データ

以下を必須受入条件とする。

- 既存settings.jsonを読み込める
- 保存済みSMTP設定を画面へ復元できる
- 保存済みkintone設定を画面へ復元できる
- 保存済みkintone mappingを復元できる
- 画面再読み込み後も設定が維持される
- SMTPだけ変更してもkintone設定が消えない
- kintoneだけ変更してもSMTP設定が消えない
- mappingだけ変更してもSMTP設定が消えない
- パスワード未変更時に既存パスワードを保持する
- パスワード実値を画面へ表示しない
- settings.json不存在とJSON破損を区別する
- JSON破損時に初期値で上書きしない
- 保存失敗時に既存設定を破壊しない
- app_settings.jsonが存在する旧環境から安全に移行できる

---

## 54-3. 回答

以下を必須とする。

- 通常回答者へ個別URL発行
- 個別回答URL発行
- 個別回答者情報入力
- 個別回答者情報確認
- アンケート回答
- 回答完了
- 回答済みトークン再利用防止
- 不正トークン拒否
- 回答期間外拒否
- 不正選択肢拒否
- 不正分岐経路拒否
- 未到達質問を未回答扱いしない
- 二重回答防止

---

## 54-4. メール

以下を必須とする。

- SMTP設定保存
- SMTP設定再読み込み
- SMTP接続確認
- テストメール
- 回答依頼メール
- 送信結果表示
- 二重送信防止
- 失敗記録
- 失敗再送
- 送信履歴確認

---

## 54-5. kintone

以下を必須とする。

- 接続設定
- 接続確認
- 対象app確認
- 最新フィールド定義取得
- フィールド定義表示
- mapping
- mapping検証
- mapping保存
- schemaHash保存
- schema変更検知
- 同期プレビュー
- 新規判定
- 更新判定
- 変更なし判定
- 対象外判定
- エラー判定
- kintoneRecordIdによる同一性判定
- 大量データ取得
- 10,000件超のカーソル方式
- 同期実行
- 同期結果保存
- 同期履歴確認
- 二重同期防止

---

## 54-6. データ整合性

以下を必須とする。

- JSON読み込み失敗時に空データへ置き換えない
- JSON破損時に既存データを上書きしない
- 書き込み失敗時に既存正常ファイルを破壊しない
- 部分的な設定保存で他設定を消さない
- 再読み込みで保存済みデータを復元できる
- 顧客削除で過去回答を失わない
- kintone削除で過去回答を失わない
- トークンと回答データの不整合を防止する
- 送信ログと回答者を関連付けられる
- 個別回答者と通常回答者を区別できる

---

## 54-7. 禁止事項

以下を禁止する。

- JSON読み込み失敗時に初期値で上書きする
- JSON読み込み失敗時に空データとして正常処理する
- settings.jsonと別の設定ファイルを正本として使用する
- 画面上のJavaScript変数だけを正本にする
- SMTP保存時にkintone設定を消す
- kintone保存時にSMTP設定を消す
- パスワードを画面へ返す
- パスワードをログへ保存する
- X-Cybozu-Authorizationを表示する
- kintoneフィールド定義を取得せず同期する
- field labelだけで同期先を確定する
- 古いschemaHashのmappingを無条件で利用する
- メールアドレスだけでkintone顧客を同一判定する
- 画面側だけでバリデーションを完結させる
- JavaScriptだけで公開・回答・同期を許可する
- 未対応field typeを暗黙に文字列化する
- 不正な分岐経路を受け入れる
- 未到達質問を未回答として集計する
- 同一トークンで二重回答させる
- 顧客削除時に過去履歴を物理削除する
- 同期途中の不完全データを成功扱いする
- コンソールだけで処理結果を通知する
- 利用者の確認なしに推測したmappingを確定する

---

## 54-8. 今回の「既存設定を読まない」問題への必須対応

今回の問題を解消するため、実装では特に以下を必須とする。

1. 設定データの正本をsettings.jsonに統一する
2. アプリ起動時または設定画面表示時にsettings.jsonを読み込む
3. 読み込んだ設定を画面へ反映する
4. 画面の初期値を保存済み設定より優先しない
5. settings.jsonが存在しない場合は未設定として扱う
6. settings.jsonが壊れている場合は読み込みエラーとして扱う
7. 壊れたsettings.jsonを初期値で上書きしない
8. 設定保存時には既存settings.jsonを読み込んでから変更する
9. SMTP設定変更時にkintone設定を消さない
10. kintone設定変更時にSMTP設定を消さない
11. パスワード未変更時に既存パスワードを保持する
12. 保存成功を確認してから成功メッセージを表示する
13. 保存後に再読み込みしても同じ値が表示されることを確認する
14. 旧app_settings.jsonがある場合は明示的な移行処理を行う
15. 新旧ファイルを同時に正本として扱わない

---

## 54-9. 実装完了の判断

実装完了とは、画面が表示されることではない。

以下の一連の操作が実際に完結することを実装完了とする。

### アンケート

アンケート作成
→
質問作成
→
分岐設定
→
保存
→
公開
→
回答依頼
→
回答
→
回答状況確認
→
回答結果確認
→
集計
→
終了

### 通常回答者

顧客
→
アンケート選択
→
回答依頼
→
個別URL発行
→
メール送信
→
回答
→
回答済み確認

### 個別回答者

個別URL発行
→
URLアクセス
→
組織名入力
→
部署名入力
→
メールアドレス入力
→
確認
→
回答
→
回答完了
→
同じURL再アクセス
→
回答済みとして扱う

### SMTP

設定入力
→
保存
→
画面再読み込み
→
保存値復元
→
接続確認
→
テストメール
→
結果確認

### kintone

接続設定
→
設定保存
→
接続確認
→
フィールド定義取得
→
フィールド定義確認
→
mapping
→
mapping検証
→
mapping保存
→
schemaHash確認
→
同期プレビュー
→
同期実行
→
同期結果確認
→
同期履歴確認

これらの一連の操作について、画面側・サーバー側・JSONデータの3者が矛盾せず動作すること。

特に、

「保存した値が次回読み込める」

「一部の設定を変更しても他の設定が消えない」

「既存データを読み込めない場合に勝手に初期値へ戻らない」

「JSONが壊れた場合に既存データを破壊しない」

ことを、実装完了の必須条件とする。
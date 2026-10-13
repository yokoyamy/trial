# 実装固定仕様（POC）

## 1. 実行環境・構成
・Apache＋PHPで動作する。
・データベースは使用しない。
・PHP／HTML／CSS／JavaScriptのアプリコードはindex.phpの1ファイルに収める。
・保存データとアクセス制御ファイルは別ファイルとする。

## 2. 保存先【固定】
・保存先はindex.phpと同じアプリルート内のstorageとする。
・PHPの__DIR__を基準とし、実行時の作業ディレクトリに依存させない。

アプリルート/
├── index.php
└── storage/
    ├── metadata.json
    ├── requests.json
    ├── files/
    │   └── ファイルID.拡張子
    ├── tmp/
    ├── deletion-retry.json
    └── .htaccess

metadata.json：
フォルダ、動画、編集、ファイルの管理情報。

requests.json：
更新要求の重複実行防止用記録。

files/：
元動画と生成済み編集動画。

tmp/：
保存確定前の一時ファイル。

deletion-retry.json：
削除に失敗したファイルの再実行用記録。

・必要なフォルダと管理ファイルは初回起動時に作成する。
・PHPに保存領域の読み書き権限を付与する。
・作成やアクセス制御に失敗した場合は処理を停止し、エラーを表示する。
・storage/.htaccessでstorage全体への直接HTTPアクセスを禁止する。
・Apache側で.htaccessのアクセス制御を有効にする。
・動画の再生とダウンロードはindex.php経由とする。
・保存先の絶対パスはクライアントに返さない。
・ブラウザのIndexedDBは正式な保存先にしない。

## 3. 互換性の基本原則
・データ構造、DOM識別子、API形式を共通契約として固定する。
・実装ごとに項目名、型、意味を独自変更しない。
・アプリの版番号とデータ形式の版番号を分離する。
・データ形式はschemaVersion:1、APIはv1とする。
・仕様変更時は変更内容と移行方法を明示する。
・既存データの初期化で仕様変更を吸収しない。
・未対応のschemaVersionは上書きせず拒否する。

## 4. データ共通規則
・ID：UUID文字列。作成後は変更しない。
・日時：UTCのISO 8601文字列。
・動画時刻：元動画の先頭からの秒数。小数を許可する。
・容量：バイト単位の整数。
・関連なし：nullを使用する。
・表示名と保存ファイル名を分離する。
・関連データはID参照で関連付ける。
・一覧表示用の入れ子構造を正式な保存形式にしない。

## 5. 管理データ【固定】
metadata.jsonのルート：
schemaVersion、folders、videos、edits、files

・folders、videos、edits、filesは配列。

folders：
id、parentFolderId、name、createdAt、updatedAt

videos：
id、folderId、name、originalFileId、createdAt、updatedAt

edits：
id、videoId、name、version、editData、
renderedFileId、createdAt、updatedAt

files：
id、storagePath、mimeType、size、createdAt

・parentFolderId:nullは最上位フォルダ。
・storagePathはstorageを基準とする相対パス。
・storagePathの例はfiles/ファイルID.mp4。
・storagePathはサーバ内部だけで管理する。
・APIの一覧応答ではstoragePathを除外する。
・編集のversionは保存成功時に増加する整数。
・editDataとrenderedFileIdは同じversionに属する。
・既存編集の更新時は期待するversionを照合する。
・初回はデフォルトフォルダを作成する。

## 6. 編集データ【固定】
editData：
schemaVersion、coordinateSystem、
sourceWidth、sourceHeight、elements

coordinateSystem：
source-pixels固定

・座標とサイズは元動画のピクセル単位。
・表示倍率やウィンドウサイズで保存値を変更しない。
・sourceWidth、sourceHeightは元動画の解像度。
・elementsは配列。

要素共通：
id、type

type：
A＝コメント
B＝強調枠
C＝拡大枠
D＝スキップ
E＝接続線
F＝PDF位置

A／B／C：
start、end、x、y、w、h、
stroke、width、dash、shape、background

Aの追加項目：
text、size、font、color

Cの追加項目：
zoom

D：
start、end

E：
ownerId、commentId、from、to、
stroke、width、dash、line、terminal

F：
time

・表示区間はstart以上end未満。
・0 <= start < end <= 元動画の長さ。
・PDF位置は区間ではなくtimeで保存する。
・0 <= time < 元動画の長さ。
・ownerIdはBまたはC、commentIdはAを参照する。
・接続線はownerIdの要素の表示区間に従う。

接続位置：
四角・角丸四角：{side、ratio}
楕円：{angle}

・side：left／right／top／bottom
・ratio：0～1。
・angle：ラジアン単位。

書式値：
dash：solid／dash／dot／chain
shape：rect／ellipse／round
font：gothic／mincho
line：straight／orthogonal／wave
terminal：none／arrow／circle
色：#RRGGBBまたはtransparent

・文字サイズは10～40。
・色パレットは12色以下。
・線の太さはビジュアルで選択する。
・コメント本文は動画枠内で直接編集する。

## 7. DOM識別子【固定】
動画領域：
stage、empty、surface、video、overlay

操作領域：
actions、primary、begin、save、
download、pdf、finish、status

表示倍率・領域境界：
zoomLabel、viewZoom、split

タイムライン：
timeline、clock、scale、scroll、
tracks、playhead、handle

動画一覧：
veil、library、tree、list、closeList、
new、upload、upload2、folder、file

補助UI：
menu、pdfDialog、print、cancelPDF、printArea

・IDを重複させない。
・videoとoverlayはsurface内に置く。
・overlayは編集描画用canvasとする。
・playheadはtracks内、handleはplayhead内に置く。
・動的一覧行はdata-entity-typeとdata-entity-idを持つ。
・動的編集要素はdata-element-idを持つ。
・動的要素に固定IDを流用しない。
・装飾用DOMは追加できるが、固定IDの役割を変更しない。

## 8. API共通形式【固定】
呼び出し先：
index.php?api=v1&action=操作名

JSONによる更新要求：
requestId、payload

成功応答：
ok:true、requestId、data

失敗応答：
ok:false、requestId、error:{code、message}

・GET要求の応答ではrequestId:nullとする。
・HTTPステータスとokを一致させる。
・入力不足、対象なし、競合、保存失敗を区別する。
・更新要求にはUUIDのrequestIdを付ける。
・再試行は同じrequestIdと同じ内容を送信する。
・成功済みの同一要求は重複実行せず、同じ結果を返す。
・同じrequestIdで内容が異なる要求は拒否する。
・動画配信の成功応答はJSONではなく動画データとする。
・HTTP GETでデータを変更しない。

## 9. API操作名と要求内容【固定】
GET list：
一覧とschemaVersionを返す。

POST folder.create：
payload:{id、parentFolderId、name}

POST folder.rename：
payload:{id、name}

POST folder.delete：
payload:{id}

POST video.upload：
multipart/form-data
metadata：
{requestId、payload:{id、folderId、name}}
file：
元動画

POST video.rename：
payload:{id、name}

POST video.move：
payload:{id、folderId}

POST video.delete：
payload:{id}

GET edit.get：
クエリのidで編集を指定。
編集データとversionを返す。

POST edit.save：
multipart/form-data
metadata：
{
  requestId、
  payload:{
    id、videoId、name、expectedVersion、editData
  }
}
file：
生成済み編集動画

・新規編集のexpectedVersionは0。
・既存編集は取得済みversionを送る。
・編集JSONと編集動画を一体で保存確定する。

POST edit.rename：
payload:{id、name}

POST edit.delete：
payload:{id}

GET file.stream：
クエリのidでファイルを指定。
HTTP Rangeに対応する。

GET file.download：
クエリのidでファイルを指定。
保存用のContent-Dispositionを返す。

・元動画はoriginalFileIdで取得する。
・編集動画はrenderedFileIdで取得する。
・クライアントに保存パスを指定させない。
・ファイルIDからサーバ内部で保存パスを解決する。

## 10. 保存・削除の整合性
・更新処理には共通の排他制御を使用する。
・動画はtmpに保存し、検証後にfilesへ移す。
・管理JSONは一時ファイルに書き込み、安全に置き換える。
・管理情報と動画ファイルの確定後に保存成功を返す。
・失敗時は直前の確定済みデータを維持する。
・編集更新では新しい動画ファイルIDを作成する。
・新しい保存版の確定前に旧編集動画を削除しない。
・要求記録との整合性を維持し、再試行で重複登録しない。
・処理中断後の未確定ファイルを検出・回収できるようにする。
・編集削除は編集JSONと編集動画を対象とする。
・動画削除は元動画と配下の編集を対象とする。
・フォルダ削除は配下の関連データを対象とする。
・ファイル削除失敗は記録し、再実行できるようにする。

## 11. 画面・編集動作
・動画選択時の初期表示は先頭とする。
・編集開始時は元動画と編集データを取得する。
・保存済み編集動画は再生成せず再生・ダウンロードする。
・動画領域とタイムラインは独立して拡大縮小できる。
・境界の上下ドラッグで高さ割合を変更できる。
・表示倍率変更で編集要素の保存値を変更しない。
・タイムラインに横スクロールを発生させない。
・スキップ区間の終了から再生を継続する。
・動画表示、PDF、編集動画生成で共通の描画規則を使う。
・PDFは指定位置の画面と編集要素を出力する。
・PDF出力前に1ページ1画面／2画面を選択できる。
・ボタン高さは20～30pxを目安とする。

## 12. 実装の分離
index.php内でも次の役割を分離する。

サーバ：
入力検証、保存処理、API応答、動画配信

クライアント：
API通信、アプリ状態、描画、編集操作

・画面処理から保存JSONを直接操作しない。
・選択状態、表示倍率、画面状態は編集データから分離する。
・旧版の項目名は移行処理で変換し、正式データに混在させない。
・過去のコード断片を無断で混在させない。
・過去コードを部分利用する必要がある場合は、理由を説明して承諾を得る。
・重複処理と未使用コードを残さない。

## 13. 検証
・再読み込み後に一覧と編集内容を復元できる。
・編集値と接続関係が保存前後で一致する。
・保存済み編集動画を再生成せず取得できる。
・名前変更、移動、削除が再取得後も反映される。
・通信失敗時に保存成功と表示されない。
・再試行でデータが重複しない。
・同時更新時にversion競合を検出できる。
・固定DOM識別子が存在し、重複していない。
・APIの操作名、項目名、型が固定仕様と一致する。
・storage内のファイルをHTTPで直接取得できない。
・PHP経由の動画再生、Range、ダウンロードが動作する。
・保存処理中断後も直前の確定済みデータを復元できる。
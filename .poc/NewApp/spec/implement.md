Apache24+PHP8.5 データベースなし
inedex.php１ファイルにPHP/CSS/HTML/Javascriptを全部入れる。
データはjson形式のファイル保存
CURLは使えない

https://n11-1041/.../index.php の固定指定を廃止

PHPへの通信先は現在の index.php を使用

file:// で直接開いた場合は、CORS回避のため無理に通信せず、Apache経由で開くよう案内

CSRFヘッダーは維持

同一オリジンならCORS設定そのものを不要にする

すべてのJavaScript処理を DOMContentLoaded 内に配置

イベント対象の存在確認を徹底

通信ボタンは通信開始前に即時無効化＋ローディング表示

PHP側のCSRF、セッション、JSON処理、名前空間、XSS対策を維持

kintone通信は指定どおり stream_context_create()＋file_get_contents() のみ
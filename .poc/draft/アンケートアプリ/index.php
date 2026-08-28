<?php
/*
 * 現在の index.php の設計上の問題:
 *
 * makeGroup() で生成したグループ:
 *   .add-question -> addEventListener()
 *
 * PHPで最初から生成したグループ:
 *   .add-question -> イベント登録なし
 *
 * これをイベント委譲へ統一する。
 */
?>

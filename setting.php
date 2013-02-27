<?php
/**
 * setting.php
 * 
 * 設定とか書いてある。
 * 
 * Author: kotarot <kotarot@terabo.net>
 * 
 * Copyright (c) 2010-2013, Rubik's Cubot
 * All rights reserved.
 */

//基本的な設定
$screen_name         = "RubiksCubot"; //botのid名
$consumer_key        = "********"; // Consumer keyの値
$consumer_secret     = "********"; // Consumer secretの値
$access_token        = "********"; // Access Tokenの値
$access_token_secret = "********"; // Access Token Secretの値

//Rubik's Cubot の設定
$scramble_fn = './data/posts/posted_scramble.csv';
$status_fn = './data/posts/posted_status.csv';

$scrambleHeader = "【スクランブル】\n";
$scrambleFooter1 = "\nタイムを返信してください。 #RubiksCubot"; //スクランブル表示のときのフッター1
$scrambleFooter2 = "\nタイムを返信するとランキングを返します。 #RubiksCubot"; //スクランブル表示のときのフッター2
$scrambleFooter3 = "\nタイムを返信するとランキングを返します。 #RubiksCubot"; //スクランブル表示のときのフッター3
$scrambleFooter4 = "\nWebサイト: http://wrcc.main.jp/cubot/ #RubiksCubot"; //スクランブル表示のときのフッター4
$errorFooterDoublePosted = 'エラー。すでに投稿されています。同じスクランブルに対して二重投稿はできません。'; //二重投稿
$errorFooterToScrambleStatus = 'エラー。タイムが反映されませんでした。タイムはスクランブルのツイートに対してリプライしてください。'; //公式スクランブル以外へのリプライ

?>
<?php
/**
 * bot.php
 * 
 * このファイルをcronで10分おきに実行している。
 * 
 * Author: KT <tera@toki.waseda.jp>
 * 
 * Copyright (c) 2010-2012, Rubik's Cubot
 * All rights reserved.
 */

require_once('./RubiksCubot.php');
$rc = new RubiksCubot();

if(date('G') === '9' && date('i') == '00') $response = $rc->autoFollow();

// 毎日18時に
if(date('G') === '18') $response = $rc->postScramble();

$response = $rc->reply(10);

?>
<?php
/**
 * RubiksCubot.php
 * 
 * キューボットの本体
 * 
 * Author: kotarot <kotaro@terabo.net>
 * 
 * Copyright (c) 2010-2013, Rubik's Cubot
 * All rights reserved.
 */

class RubiksCubot {
	private $_screen_name;
	private $_consumer_key;
	private $_consumer_secret;
	private $_access_token;
	private $_access_token_secret;
	
	private $_scrambleHeader;
	private $_scrambleFooter;
	private $_errorFooterDoublePosted;
	private $_errorFooterToScrambleStatus;
	
	private $_repliedReplies;
	private $_logDataFile;
	private $_latestReply;
	
	private $_scramble_fn;
	private $_status_fn;
	
	function __construct() {
		$dir = getcwd();
		$path = $dir . '/PEAR';
		set_include_path(get_include_path() . PATH_SEPARATOR . $path);
		$inc_path = get_include_path();
		chdir(dirname(__FILE__));
		date_default_timezone_set('Asia/Tokyo');
		
		require_once('setting.php');
		$this->_screen_name = $screen_name;
		$this->_consumer_key = $consumer_key;
		$this->_consumer_secret = $consumer_secret;
		$this->_access_token = $access_token;
		$this->_access_token_secret = $access_token_secret;
		
		$this->_scramble_fn = $scramble_fn;
		$this->_status_fn = $status_fn;
		
		$this->_scrambleHeader = $scrambleHeader;
		$this->_scrambleFooter = array(
			$scrambleFooter1,
			$scrambleFooter2,
			$scrambleFooter3,
			$scrambleFooter4
		);
		$this->_errorFooterDoublePosted = $errorFooterDoublePosted;
		$this->_errorFooterToScrambleStatus = $errorFooterToScrambleStatus;
		
		$this->_repliedReplies = array();
		$this->_logDataFile = './data/replies/last_replied.dat';
		$this->_latestReply = file_get_contents($this->_logDataFile);
		
		require_once 'HTTP/OAuth/Consumer.php';  
		$this->consumer = new HTTP_OAuth_Consumer($this->_consumer_key, $this->_consumer_secret);
		$http_request = new HTTP_Request2();
		$http_request->setConfig('ssl_verify_peer', false);
		$consumer_request = new HTTP_OAuth_Consumer_Request;
		$consumer_request->accept($http_request);
		$this->consumer->accept($consumer_request);
		$this->consumer->setToken($this->_access_token);
		$this->consumer->setTokenSecret($this->_access_token_secret);
		
		$this->printHeader();
	}
	
	function __destruct() {
		$this->printFooter();
	}
	
	//どこまでリプライしたかを覚えておく
	function saveLog() {
		rsort($this->_repliedReplies);
		$fp = fopen($this->_logDataFile, 'w');
		fwrite($fp, $this->_repliedReplies[0]);
		fclose($fp);
	}
	
	//表示用HTML
	function printHeader() {
		$header = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';
		$header .= '<html xmlns="http://www.w3.org/1999/xhtml" lang="ja" xml:lang="ja">';
		$header .= '<head>';
		$header .= '<meta http-equiv="content-language" content="ja" />';
		$header .= '<meta http-equiv="content-type" content="text/html; charset=UTF-8" />';
		$header .= '<title>RubiksCubot</title>';
		$header .= '</head>';
		$header .= '<body><pre>';
		print $header;
	}
	
	//表示用HTML
	function printFooter() {
		echo '</body></html>';
	}
	
	//スクランブルをポスト
	function postScramble() {
		if($this->isScramblePosted()) {
			$message = '本日はすでに投稿済みです。<br />';
			echo $message;
			return array('error'=> $message);
		} else {
			$status = $this->makeScramble();
			$response = $this->setUpdate(array('status'=>$status));
			//スクランブルのstatus_idをposted_scramble.csvに書き込む
			$this->myfputcsv($this->_scramble_fn, array($response->id, str_replace("\n", ' ', $status), date('Ymd')));
			return $this->showResult($response);
		}
	}
	
	function isScramblePosted() {
		$_date = date('Ymd');
		$buffer = file($this->_scramble_fn);
		$line = explode(',', $buffer[count($buffer) - 1]);
		if (rtrim($line[2]) === $_date && $line[0] !== '') {
			return true;
		} else {
			return false;
		}
	}
	
	//スクランブルを作る
	function makeScramble() {
		$scrambleStr = '';
		$face = array();
		$symbol = array(
			array('U', "U'", 'U2'),
			array('R', "R'", 'R2'),
			array('F', "F'", 'F2'),
			array('B', "B'", 'B2'),
			array('L', "L'", 'L2'),
			array('D', "D'", 'D2')
		);
		for($i = 0; $i < 25; $i++) {
			if($i == 0) {
				$face[$i] = rand(0, 5);
			} elseif ($i == 1) {
				while(true) {
					$face[$i] = rand(0, 5);
					if($face[$i] != $face[$i - 1]) break;
				}
			} else {
				while(true) {
					$face[$i] = rand(0, 5);
					if($face[$i] != $face[$i - 1]) {
						if($face[$i - 1] + $face[$i - 2] != 5) break;
						elseif($face[$i - 1] + $face[$i - 2] == 5 && $face[$i] != $face[$i - 2]) break;
					}
				}
			}
			$scrambleStr .= $symbol[$face[$i]][rand(0, 2)].' ';
		}
		rtrim($scrambleStr);
		$status = $this->_scrambleHeader . $scrambleStr . $this->_scrambleFooter[rand(0, 3)];
		return $status;
	}
	
	//リプライする
	function reply($cron) {
		//リプライを取得
		$response = $this->getReplies();
		$replies = $this->getRecentTweets($response, $cron);
		//var_dump($replies);
		if(count($replies) != 0) {
			//古い順にする
			$replies = array_reverse($replies);
			if(count($replies) != 0) {
				//リプライの文章をつくる
				$replyTweets = $this->makeReplyTweets($replies);
				foreach($replyTweets as $re) {
					$value = array('status' => $re['status'], 'in_reply_to_status_id' => $re['in_reply_to_status_id']);
					if($re['isPost']) {
						$response = $this->setUpdate($value);
						//ツイートのstatus_idをposted_status.csvに書き込む
						$this->myfputcsv($this->_status_fn, array($response->id, str_replace("\n", ' ', $re['status']), date('Y/m/d H:i:s')));
						$result = $this->showResult($response);
						$results[] = $result;
						if($response->in_reply_to_status_id) {
							$this->_repliedReplies[] = $response->in_reply_to_status_id;
						}
					}
				}
			}
		} else {
			$message = $cron.'分以内に受け取った@replyはないようです。<br /><br />';
			echo $message;
			$results[] = $message;
		}
		if (!empty($this->_repliedReplies)) {
			$this->saveLog();
		}
		return $results;
	}
	
	//リプライを作る
	function makeReplyTweets($replies) {
		$replyTweets = array();
		foreach($replies as $reply) {
			$screen_name = $reply->user->screen_name;
			$user_id = (string)$reply->user->id;
			$reply_text = $reply->text;
			$reply_id = (string)$reply->id;
			$scramble_id = (string)$reply->in_reply_to_status_id;
			$created_at = $reply->created_at;
			$profile_image_url = $reply->user->profile_image_url;
			$isDNF = false;
			$isGeso = false;
			$isDaijobu = false;
			$re['isPost'] = true; //postするか否か
			if(preg_match('/\d{1,3}[\.|,]\d{1,2}/s', $reply_text) || preg_match('/DNF/is', $reply_text)) {
				if((strpos($reply_text, 'ゲソ') || strpos($reply_text, 'イカ')) !== false) $isGeso = true;
				if(strpos($reply_text, '大丈夫か') !== false) $isDaijobu = true;
				//公式スクランブルに対するリプライかどうかチェック
				//if($this->patternErrorCheckToScrambleStatus($scramble_id)) {
					//二重投稿か否かチェック
					if(!$this->patternErrorCheckDoublePosted($user_id, $scramble_id)) {
						if(preg_match('/\d{1,2}:\d{1,2}[\.|,]\d{1,2}/s', $reply_text)) {
							$divtime = explode(':', $reply_text);
							preg_match('/\d{1,2}/', $divtime[0], $matches);
							$minute = (int)$matches[0];
							preg_match('/\d{1,2}[\.|,]\d{1,2}/', $divtime[1], $matches);
							$second = (float)$matches[0];
							$solvedTime = $minute * 60 + $second;
						} elseif(preg_match('/\d{1,3}[\.|,]\d{1,2}/s', $reply_text, $matches)) {
							$solvedTime = (float)$matches[0];
						}
						if(preg_match('/\d{1,3}[\.|,]\d{1,2}.?\+/s', $reply_text)) {
							$solvedTime += 2;
						}
						if(preg_match('/DNF/is', $reply_text)) {
							$isDNF = true;
							$solvedTime = 9999.99;
						}
						//タイム処理へ
						$re['status'] = $this->patternRecord($user_id, $screen_name, $profile_image_url, $scramble_id, $created_at, $solvedTime, $isDNF, $isGeso, $isDaijobu);
					} else {
						//二重投稿だった場合、エラー文を吐き出す
						$re['status'] = $this->patternErrorDoublePosted($screen_name, $isGeso, $isDaijobu);
					}
				//} else {
					//スクランブル以外に対するリプライだった場合
				//	$re['status'] = $this->patternErrorToScrambleStatus($screen_name, $isGeso, $isDaijobu);
				//}
			} else {
				//関係のないリプライの場合 無視する。
				$re['status'] = '';
				$re['isPost'] = false;
			}
			$re['in_reply_to_status_id'] = $reply_id;
			$replyTweets[] = $re;
		}
		return $replyTweets;
	}
	
	//記録処理
	function patternRecord($user_id, $screen_name, $profile_image_url, $scramble_id, $created_at, $solvedTime, $isDNF, $isGeso, $isDaijobu) {
		//idとscreen_nameのリスト更新
		$this->updateNameList($user_id, $screen_name);
		//ツイート時刻を取得する
		//$solvedDate = $this->getSolvedDate($created_at);
		$solvedDate = date('Y/m/d H:i:s', strtotime($created_at));
		//デイリーランキング処理へ
		$dRank = $this->updateDRank($user_id, $scramble_id, $solvedDate, $solvedTime);
		$numDRank = $this->getNumDRank($scramble_id);
		//月間ランキング処理へ
		$mRank = $this->updateMRank($user_id, $scramble_id, $solvedDate, $solvedTime);
		//総合ランキング処理へ
		$aRank = $this->updateARank($user_id, $scramble_id, $solvedDate, $solvedTime);
		//個人データ更新
		$peronalDate = $this->updatePersonalData($user_id, $screen_name, $profile_image_url, $scramble_id, $solvedDate, $solvedTime, $aRank, $isDNF);
		//DNFかどうかによって場合わけ
		$status = '@'.$screen_name.' ';
		if(!$isDNF) {
			$solvedTime = $this->convertTime($solvedTime);
			if($isGeso) $status .= '今回のタイムは '.$solvedTime.'ゲソ。このスクランブルでのランキングは'.$dRank.'位('.$numDRank."人中)でゲソ。明日も挑戦するでゲソ！\n";
			else if($isDaijobu) $status .= '大丈夫だ、問題ない。'.$dRank.'位('.$numDRank."人中)だ。神は言っている、明日も挑戦するべきだと。\n";
			else $status .= '今回のタイムは '.$solvedTime.'です。このスクランブルでのランキングは'.$dRank.'位('.$numDRank."人中)です。\n";
		} else {
			if($isGeso) $status .= "今回はDNFだったでゲソ。明日も挑戦するでゲソ！\n";
			else if($isDaijobu) $status .= "DNFだ、問題だ。神は言っている、明日も挑戦するべきだと。\n";
			else $status .= "今回は残念ながら DNF です。\n";
		}
		$status .= 'ランキング: http://wrcc.main.jp/cubot/';
		return $status;
	}
	
	function convertTime($_time) {
		if($_time != 9999.99) {
			$minute = floor($_time / 60);
			$secondStr = sprintf('%02.2f', $_time - $minute * 60);
			$timeStr = '';
			if($minute > 0) {
				$timeStr = $minute.':';
				if($secondStr < 10) $secondStr = '0'.$secondStr;
			}
			$timeStr .= $secondStr;
		} else {
			$timeStr = 'DNF';
		}
		return $timeStr;
	}
	
	//user_idとscreen_nameの対応リスト作成
	function updateNameList($user_id, $screen_name) {
		$exist = false; //idがすでにファイルに存在するか
		$needSort = true; //ファイルを並べ替える必要があるか
		$isChanged = false; //ファイルを書き換える必要があるか
		$fn = './data/lists/id_name.csv';
		$buffer = file($fn);
		for($i = 0; $i < count($buffer); $i++) {
			$line = explode(',', $buffer[$i]);
			if($line[0] == $user_id) {
				$exist = true;
				$needSort = false;
				if($line[1] !== $screen_name) {
					$line[1] = $screen_name;
					$buffer[$i] = implode(',', $line);
					$isChange = ture;
				}
				break;
			}
		}
		//idが存在しなかった場合、1番下に追加
		if(!$exist) {
			$buffer[] = implode(',', array($user_id, $screen_name));
			$inChange = true;
		}
		//並べ替える必要があるとき、並べ替える
		if($needSort) {
			$head = array_shift($buffer);
			usort($buffer, array($this, 'cmp0a'));
			array_unshift($buffer, $head);
			$isChange = true;
		}
		//書き換える必要がある場合、書き換え
		if(isChanged) {
			$fp = fopen($fn, 'w');
			foreach($buffer as $lines) {
				fwrite($fp, rtrim($lines)."\n");
			}
			fclose($fp);
		}
	}
	
	//個人記録ファイル処理
	function updatePersonalData($user_id, $screen_name, $profile_image_url, $scramble_id, $solvedDate, $solvedTime, $aRank, $isDNF) {
		$pdPath = './data/personal_data/' . $user_id;
		$dfn = $pdPath . '/data.csv';
		$rfn = $pdPath . '/records.dat';
		if(!is_dir($pdPath)) {
			mkdir($pdPath);
			$this->myfputcsv($dfn, array("scramble_id", "solved_time", "scramble_date", "solved_date", "comment"));
			$this->makeRecordsDat($rfn); //records.datファイルの初期化
		}
		
		//data.csvの更新
		$_scrambleDate = $this->getScrambleDate($scramble_id);
		$scrambleDate = $_scrambleDate[1];
		$comment = '';
		if(!$isDNF) $this->myfputcsv($dfn, array($scramble_id, $solvedTime, $scrambleDate, $solvedDate, $comment));
		else $this->myfputcsv($dfn, array($scramble_id, 'DNF', $scrambleDate, $solvedDate, $comment));
		
		//records.datの更新
		$buffer = file($rfn);
		$bestTime = false;
		for($i = 0; $i < count($buffer); $i++) {
			rtrim($buffer[$i]);
			$line = explode('=', $buffer[$i]);
			
			switch($i) {
				case 0:
					$line[1] = $screen_name;
					break;
				case 1:
					$line[1] = $profile_image_url;
					break;
				case 2:
					if($line[1] == 0) {
						$line[1] = $solvedTime;
						$bestTime = true;
					} else if($line[1] > $solvedTime) {
						$line[1] = $solvedTime;
						$bestTime = true;
					}
					break;
				case 3:
					if($bestTime) $line[1] = $scramble_id;
					break;
				case 4:
					if($bestTime) $line[1] = $scrambleDate;
					break;
				case 5:
					$line[1] = $aRank;
					break;
				case 6:
					if($line[1] == 0) $line[1] = $aRank;
					if($line[1] > $aRank) $line[1] = $aRank;
					break;
				default:
					break;
			}
			$buffer[$i] = rtrim(implode('=', $line));
		}
		$fp = fopen($rfn, 'w');
		foreach($buffer as $lines) {
			fwrite($fp, $lines."\n");
		}
		fclose($fp);
	}
	
	//records.datファイルの初期化
	function makeRecordsDat($fn) {
		$fp = fopen($fn, 'w');
		$datStr = "screen_name=0\nimg_url=0\nbest_time=0\nscramble_id_of_best_time=0\nscramble_date_of_best_time=0\ncurrent_aRank=0\nbest_aRank=0\ncomment=\n";
		fwrite($fp, $datStr);
		fclose($fp);
	}
	
	//デイリーランキング処理
	function updateDRank($user_id, $scramble_id, $solvedDate, $solvedTime) {
		$_date = $this->getScrambleDate($scramble_id);
		$fn = './data/rankings/d/' . $_date[0] . '.csv';
		if(!file_exists($fn)) {
			$this->myfputcsv($fn, array("user_id", "time", "scramble_id", "scramble_date", "solved_date"));
		}
		$this->myfputcsv($fn, array($user_id, $solvedTime, $scramble_id, $_date[1], $solvedDate));
		$buffer = file($fn);
		$head = array_shift($buffer);
		usort($buffer, array($this, 'cmp1a'));
		array_unshift($buffer, $head);
		$fp = fopen($fn, 'w');
		foreach($buffer as $line) {
			fwrite($fp, $line);
		}
		$fp = fclose($fp);
		$dRank = 1;
		for($dRank = 1; $dRank < count($buffer); $dRank++) {
			$line = explode(',', $buffer[$dRank]);
			if($line[0] == $user_id) break;
		}
		return $dRank;
		
	}
	
	//ランキング総数を取得
	function getNumDRank($scramble_id) {
		$_date = $this->getScrambleDate($scramble_id);
		$fn = './data/rankings/d/' . $_date[0] . '.csv';
		$buffer = file($fn);
		$i = -1;
		foreach($buffer as $line) {
			$i++;
		}
		return $i;
	}
	
	//月間ランキング処理
	function updateMRank($user_id, $scramble_id, $solvedDate, $solvedTime) {
		$_date = $this->getScrambleDate($scramble_id);
		$_month = mb_substr($_date[0], 0, 6);
		$rfn = './data/rankings/m/' . $_month . '.csv';
		if(!file_exists($rfn)) {
			$this->myfputcsv($rfn, array("user_id", "time", "scramble_id", "scramble_date", "solved_date"));
		}
		$buffer = $this->isUpdate($user_id, $solvedTime, $rfn);
		if($buffer !== false) {
			$buffer[] = implode(',', array($user_id, $solvedTime, $scramble_id, $_date[1], $solvedDate))."\n";
			$head = array_shift($buffer);
			usort($buffer, array($this, 'cmp1a'));
			array_unshift($buffer, $head);
			$fp = fopen($rfn, 'w');
			foreach($buffer as $line) {
				fwrite($fp, $line);
			}
			$fp = fclose($fp);
		}
		$mRank = 1;
		for($mRank = 1; $mRank < count($buffer); $mRank++) {
			$line = explode(',', $buffer[$mRank]);
			if($line[0] == $user_id) break;
		}
		return $mRank;
	}
	
	//総合ランキング処理
	function updateARank($user_id, $scramble_id, $solvedDate, $solvedTime) {
		$_date = $this->getScrambleDate($scramble_id);
		$rfn = './data/rankings/a/rankings.csv';
		$buffer = $this->isUpdate($user_id, $solvedTime, $rfn);
		if($buffer !== false) {
			$buffer[] = implode(',', array($user_id, $solvedTime, $scramble_id, $_date[1], $solvedDate))."\n";
			$head = array_shift($buffer);
			usort($buffer, array($this, 'cmp1a'));
			array_unshift($buffer, $head);
			$fp = fopen($rfn, 'w');
			foreach($buffer as $line) {
				fwrite($fp, $line);
			}
			$fp = fclose($fp);
		}
		$aRank = 1;
		for($aRank = 1; $aRank < count($buffer); $aRank++) {
			$line = explode(',', $buffer[$aRank]);
			if($line[0] == $user_id) break;
		}
		return $aRank;
	}
	
	//（月間・総合のとき）ベストタイムで更新するか
	function isUpdate($user_id, $solvedTime, $fn) {
		$isFirst = true;
		$buffer = file($fn);
		for($i = 1; $i < count($buffer); $i++) {
			$line = explode(',', $buffer[$i]);
			if($line[0] == $user_id) {
				$isFirst = false;
				if($line[1] > $solvedTime) {
					array_splice($buffer, $i, 1);
					return $buffer; //ベストタイム更新のとき
				}
			}
		}
		if($isFirst) return $buffer; //初回
		else return false; //ベストタイムでない
	}
	
	//エラーパターン1 二重投稿チェック用
	function patternErrorCheckDoublePosted($user_id, $scramble_id) {
		$fn = './data/personal_data/'.$user_id.'/data.csv';
		$isDouble = false;
		if(file_exists($fn)) {
			$buffer = file($fn);
			foreach($buffer as $lines) {
				$line = explode(',', $lines);
				if($line[0] == $scramble_id) {
					$isDouble = true;
					break;
				}
			}
		}
		return $isDouble;
	}
	
	//エラーパターン1 二重投稿
	function patternErrorDoublePosted($screen_name, $isGeso, $isDaijobu) {
		$status = '@'.$screen_name.' ';
		if ($isGeso) $status .= 'エラーでゲソ。すでに投稿されてるでゲソ。同じスクランブルに対して二重投稿はできないでゲソ。';
		else if($isDaijobu) $status .= '大丈夫じゃない、問題だ。二重投稿エラーだ。';
		else $status .= $this->_errorFooterDoublePosted;
		return $status;
	}
	
	//エラーパターン2 スクランブルに対するリプライかどうか
	function patternErrorCheckToScrambleStatus($scramble_id) {
		$isToScramble = false;
		$buffer = file($this->_scramble_fn);
		foreach($buffer as $lines) {
			$line = explode(',', $lines);
			if($line[0] == $scramble_id) {
				$isToScramble = true;
				break;
			}
		}
		return $isToScramble;
	}
	
	//エラーパターン2 タイムのリプライでかつスクランブル以外に対するリプライだった場合
	function patternErrorToScrambleStatus($screen_name, $isGeso, $isDaijobu) {
		$status = '@'.$screen_name.' ';
		if($isGeso) $status .= 'エラーでゲソ。タイムが反映されなかったでゲソ。タイムはスクランブルのツイートに対してリプライするでゲソ。';
		else if($isDaijobu) $status .= '大丈夫じゃない、問題だ。リプライ先エラーだ。';
		else $status .= $this->_errorFooterToScrambleStatus;
		return $status;
	}
	
	//csvファイルの最終行に追加
	function myfputcsv($fn, $arr) {
		$fp = fopen($fn, 'a');
		fwrite($fp, implode(',', $arr)."\n");
		fclose($fp);
	}
	
	//スクランブルidから日付を取得する
	function getScrambleDate($scramble_id) {
		$buffer = file($this->_scramble_fn);
		foreach($buffer as $lines) {
			$line = explode(',', $lines);
			if($line[0] == $scramble_id) {
				$date1 = rtrim($line[2]);
				break;
			}
		}
		$date2 = array(mb_substr($date1, 0, 4), mb_substr($date1, 4, 2), mb_substr($date1, 6, 2));
		$date3 = implode('/', $date2);
		$_date = array($date1, $date3);
		return $_date; //Ymdフォーマット, Y/m/dフォーマット
	}
	
	//comparison functions
	function cmp0a($a, $b) {
		$_a = explode(',', $a);
		$_b = explode(',', $b);
		if($_a[0] == $_b[0]) {return 0;}
		if($_a[0] < $_b[0]) {return -1;}
		return 1;
	}
	
	function cmp1a($a, $b) {
		$_a = explode(',', $a);
		$_b = explode(',', $b);
		if($_a[1] == $_b[1]) {return 0;}
		if($_a[1] < $_b[1]) {return -1;}
		return 1;
	}
	
	//つぶやきの中から$minute分以内かつ最後にリプライした以降のものを返す
	function getRecentTweets($response, $minute) {
		$tweets = array();
		$now = strtotime('now');
		$limittime = $now - $minute * 70; //取りこぼしを防ぐために10秒多めにカウントしてる
		foreach($response as $tweet) {
			//var_dump($tweet);
			$_time = strtotime($tweet->created_at);
			//echo $time.' = '.$limittime.'<br />';
			$tweet_id = (string)$tweet->id;
			if($limittime <= $_time && $this->_latestReply < $tweet_id) {
				$tweets[] = $tweet;
			} else {
				break;
			}
		}
		return $tweets;
	}
	
	//自動フォロー返し
	function autoFollow() {
		$response = $this->getFollowers();
		$followList = array();
		foreach($response as $user) {
			$follow = (string)$user->following;
			if($follow == 'false') {
				$followList[] = (string)$user->screen_name;
			}
		}
		foreach($followList as $screen_name) {
			$response = $this->followUser($screen_name);
		}
	}
	
	//結果を表示する
	function showResult($response) {
		if(!$response->error) {
			$message = 'Twitterへの投稿に成功しました。<br />';
			$message .= '@<a href="http://twitter.com/' .$response->user->screen_name.'" target="_blank">'.$response->user->screen_name.'</a>';
			$message .= ' が投稿したメッセージ：<br />'.$response->text.'<br />';
			$message .= 'URL: <a href="http://twitter.com/' .$response->user->screen_name.'/status/'.$response->id.'" target="_blank">http://twitter.com/' .$response->user->screen_name.'/status/'.$response->id.'</a><br /><br />';
			echo $message;
			//var_dump($response);
			return array('result'=> $message);
		} else {
			$message = 'Twitterへの投稿に失敗しました。<br />';
			$message .= 'ユーザー名：@<a href="http://twitter.com/' .$this->_screen_name.'" target="_blank">'.$this->_screen_name.'</a><br /><br />';
			echo $message;
			var_dump($response);
			return array('error' => $message);
		}
	}
	
	//基本的なAPIを叩く
	function _setData($url, $value = array()) {
		$response = $this->consumer->sendRequest($url, $value, 'POST');
		//$response = simplexml_load_string($response->getBody());
		$response = json_decode($response->getBody());
		return $response;
	}
	function _getData($url) {
		$response = $this->consumer->sendRequest($url, array(), 'GET');
		//$response = simplexml_load_string($response->getBody());
		$response = json_decode($response->getBody());
		return $response;
	}
	function setUpdate($value) {
		//$url = 'https://twitter.com/statuses/update.xml';
		$url = 'https://api.twitter.com/1.1/statuses/update.json';
		return $this->_setData($url, $value);
	}
	function getFriendsTimeline() {
		$url = 'http://twitter.com/statuses/friends_timeline.xml';
		return $this->_getData($url);
	}
	function getReplies($page = false) {
		//$url = 'http://twitter.com/statuses/replies.xml';
		$url = 'https://api.twitter.com/1.1/statuses/mentions_timeline.json';
		if($page) {
			$url .= '?page=' . intval($page);
		}
		return $this->_getData($url);
	}
	function getFriends($id = null) {
		$url = 'http://twitter.com/statuses/friends.xml';
		return $this->_getData($url);
	}
	function getFollowers() {
		$url = 'http://twitter.com/statuses/followers.xml';
		return $this->_getData($url);
	}
	function followUser($screen_name) {
		$url = 'http://twitter.com/friendships/create/' .$screen_name.'.xml';
		return $this->_setData($url);
	}
}


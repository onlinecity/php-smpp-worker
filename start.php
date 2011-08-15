<?php
declare(ticks = 1);
chdir(dirname(__FILE__));
set_time_limit(0);

require_once 'factory.class.php';

function debug($string) {
	list($microtime, $time) = explode(' ',microtime());
	$prefix = sprintf("%s.%02.2s P:%05s ",date('Y-m-d\TH:i:s'),(int) ($microtime*1000000),getmypid());
	$s = $prefix.str_replace("\n","\n".$prefix."\t",trim($string))."\n";
	file_put_contents('debug.log',$s,FILE_APPEND);
}

function protocolDebug($string) {
	list($microtime, $time) = explode(' ',microtime());
	$prefix = sprintf("%s.%02.2s P:%05s ",date('Y-m-d\TH:i:s'),(int) ($microtime*1000000),getmypid());
	$s = $prefix.str_replace("\n","\n".$prefix."\t",trim($string))."\n";
	file_put_contents('trace.log',$s,FILE_APPEND);
}

$f = new SmsFactory();
$f->startAll();
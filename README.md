Multi process PHP-based workers for SMPP
=============

Requirements
-----
 * PHP 5.3+
 * [redis 2.1.7+](http://redis.io/)
 * [phpredis](https://github.com/nicolasff/phpredis) - tested with: nicolasff/phpredis@1d6133d4cfc71c555ab4b8551d2818925f7cb444 must support [brpoplpush](http://redis.io/commands/brpoplpush)
 * [igbinary](https://github.com/dynamoid/igbinary) (optional)
 * php extensions
  * [posix](http://dk.php.net/manual/en/book.posix.php)
  * [pcntl](http://dk.php.net/manual/en/book.pcntl.php)
  * [sockets](http://dk.php.net/manual/en/book.sockets.php)
  * [pcre](http://dk.php.net/manual/en/book.pcre.php)
  * [pcre](http://dk.php.net/manual/en/book.pcre.php)
  * [mbstring](http://dk.php.net/manual/en/ref.mbstring.php)

Submodule
-----
This project use the following submodule [onlinecity/php-smpp](https://github.com/onlinecity/php-smpp/).

So remember to initialize it when you checkout this project:
```
git submodule init && git submodule update
```

Simple test usage (send 10x100 messages)
-----
Run start.php to startup all processes, then inject messages into queue with script below.

``` php
<?php
require_once 'queuemodel.class.php';
$options = parse_ini_file('options.ini',true);
$q = new QueueModel($options);

$m = array();
for ($n=0;$n<10;$n++) {
	$r = array();
	for($i=0;$i<100;$i++) {
		$r[] = 4512345678;
	}
	
	$m[] = new SmsMessage(1234, 'Test', 'Lorem ipsum', $r);	
}

$q->produce($m);
```

Configure
-----
You'll find all configurable options in the options.ini file.
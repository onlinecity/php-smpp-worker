<?php
require_once 'queuemanager.class.php';
require_once 'smsreceiver.class.php';
require_once 'smssender.class.php';

/**
 * Factory class for the SmsSender and SmsReceiver workers.
 * This class will fork the required amount of workers, and keep them running until it's closed.
 * The implementation uses the posix and pcntl extensions to support forking.
 *
 * Copyright (C) 2011 OnlineCity
 * Licensed under the MIT license, which can be read at: http://www.opensource.org/licenses/mit-license.php
 * @author hd@onlinecity.dk
 */
class SmsFactory
{
	protected $numSenders;

	protected $options;
	protected $debugHandler;
	
	protected $senderPids;
	protected $receiverPid;
	protected $queueManagerPid;

	public static $pidFile = 'parent.pid';
	public static $optionsFile = 'options.ini';

	public function __construct()
	{
		$this->senderPids = array();
	}

	/**
	 * Start all workers.
	 *
	 */
	public function startAll()
	{
		$this->options = parse_ini_file(self::$optionsFile,true);
		if ($this->options === false) throw new InvalidArgumentException('Invalid options ini file, can not start');
		
		$this->numSenders = $this->options['factory']['senders'];
		
		$this->debug("Factory started with pid: ".getmypid());
		file_put_contents(self::$pidFile, getmypid());

		$this->fork();
	}
	
	/**
	 * Shorthand method for calling debug handler
	 * @param string $s
	 */
	private function debug($s)
	{
		call_user_func($this->options['general']['debug_handler'], $s);
	}
	
	private function fork()
	{
		$signalInstalled = false;
		
		
		for($i=0;$i<($this->numSenders+2);$i++) {
			switch ($pid = pcntl_fork()) {
				case -1: // @fail
					die('Fork failed');
					break;
				case 0: // @child
					if (!isset($this->queueManagerPid)) {
						$worker = new QueueManager($this->options);
					} else if (!isset($this->receiverPid)) {
						$worker = new SmsReceiver($this->options);
					} else {
						$worker = new SmsSender($this->options);
					}
					$this->debug("Constructed: ".get_class($worker)." with pid: ".getmypid());
					$worker->run();
					break;
				default: // @parent
					if (!isset($this->queueManagerPid)) {
						$this->queueManagerPid = $pid;
					} else if (!isset($this->receiverPid)) {
						$this->receiverPid = $pid;
					} else {
						$this->senderPids[$pid] = $pid;
					}

					if ($i<($this->numSenders+1)) continue; // fork more
					
					if (!$signalInstalled) {
						// for pcntl_sigwaitinfo to work we must install dummy handlers
						pcntl_signal(SIGTERM, function($sig) {});
						pcntl_signal(SIGCHLD, function($sig) {}); 
						$signalInstalled = true;
					}
					
					// All children are spawned, wait for something to happen, and respawn if it does
					
					$info = array();
					pcntl_sigwaitinfo(array(SIGTERM,SIGCHLD), $info);
					
					if ($info['signo'] == SIGTERM) {
						$this->debug('Factory terminating');
						foreach ($this->senderPids as $child) {
							posix_kill($child,SIGTERM);
						}
						posix_kill($this->receiverPid,SIGTERM);
						posix_kill($this->queueManagerPid,SIGTERM);
						$res = pcntl_signal_dispatch();
						exit();
					} else { // Something happend to our child
						$exitedPid = $info['pid'];
						$status = $info['status'];
						
						// What happened to our child?
						if (pcntl_wifsignaled($status)) {
							$what = 'was signaled';
						} else if (pcntl_wifexited($status)) {
							$what = 'has exited';
						} else {
							$what = 'returned for some reason';
						}
						$this->debug("Pid: $exitedPid $what");
						
						// Sleep one second to prevent aggressive respawns and to allow signal processing to complete
						$this->debug("One second respawn timeout...");
						sleep(1);
						
						// Reap all children (otherwise we get zombies)
						do {
							$rpid = pcntl_waitpid(-1,$ws,WNOHANG);
							if ($rpid) $this->debug("Reaped PID: $rpid");
						} while ($rpid != 0);
					}
					
					// Respawn
					if ($exitedPid == $this->queueManagerPid) {
						unset($this->queueManagerPid);
						$c = 'QueueManager';
					} else if ($exitedPid == $this->receiverPid) {
						unset($this->receiverPid);
						$c = 'SmsReceiver';
					} else {
						unset($this->senderPids[$exitedPid]);
						$c = 'SmsSender';
					}
					$i--;
					$this->debug("Will respawn new $c to cover loss");
					
					// Check if any other children died (they might fail simultaneously)
					// For this to work children must be reaped first (zombies still has the same SID)
					$mySid = posix_getsid(getmypid());
					if (isset($this->queueManagerPid)) {
						$sid = posix_getsid($this->queueManagerPid);
						if ($sid === false || $sid != $mySid) {
						 	unset($this->queueManagerPid);
						 	$i--;
						 	$this->debug("Will *also* respawn new QueueManager to cover loss");
						 }
					}
					if (isset($this->receiverPid)) {
						$sid = posix_getsid($this->receiverPid);
						if ($sid === false || $sid != $mySid) {
							unset($this->receiverPid);
							$i--;
							$this->debug("Will *also* respawn new SmsReceiver to cover loss");
						}
					}
					foreach ($this->senderPids as $senderPid) {
						echo "PID: ".$senderPid." SID:";
						$sid = posix_getsid($senderPid);
						var_dump($sid);
						if ($sid === false || $sid != $mySid) {
							unset($this->senderPids[$senderPid]);
							$i--;
							$this->debug("Will *also* respawn new SmsSender to cover loss of PID:$senderPid");
						}
					}
					
					continue;
					break;
			}
		}
	}
}

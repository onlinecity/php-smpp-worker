<?php
require_once 'queuemodel.class.php';

/**
 * Queue manager/helper for monitoring deferred messages.
 * Messages will be held in the deferred queue for a configurable time, and then 
 * retried a configurable number of times before finally failing completely.
 * 
 * Copyright (C) 2011 OnlineCity
 * Licensed under the MIT license, which can be read at: http://www.opensource.org/licenses/mit-license.php
 * @author hd@onlinecity.dk
 */
class QueueManager
{
	protected $options;
	protected $debug;
	
	/**
	 * Construct a new QueueManager worker
	 * @param array $options
	 */
	public function __construct($options)
	{
		$this->options = $options;
		$this->debug = $this->options['queuemanager']['debug'];
		pcntl_signal(SIGTERM, array($this,"disconnect"), true);
		gc_enable();
	}
	
	/**
	 * Close the connection to the queue
	 */
	public function disconnect()
	{
		if (isset($this->queue)) $this->queue->close();
	}
	
	/**
	* Shorthand method for calling debug handler
	* @param string $s
	*/
	private function debug($s)
	{
		call_user_func($this->options['general']['debug_handler'], 'PID:'.getmypid().' - '.$s);
	}
	
	/**
	* Run garbage collect and check memory limit
	*/
	private function checkMemory()
	{
		// Run garbage collection
		gc_collect_cycles();
	
		// Check the memory usage for a limit, and exit when 64MB is reached. Parent will re-fork us
		if ((memory_get_usage(true)/1024/1024)>64) {
			$this->debug('Reached memory max, exiting');
			exit();
		}
	}
	
	/**
	 * This service's main loop
	 */
	public function run()
	{
		$this->queue = new QueueModel($this->options);
		
		openlog('php-smpp',LOG_PID,LOG_USER);

		while (true) {
			// commit suicide if the parent process no longer exists
			if (posix_getppid() == 1) exit();
			
			// Do the queue have any deferred messages for us?
			$deferred = $this->queue->lastDeferred(); /* @var $deferred SmsMessage */
			if (!$deferred) { // Idle
				$this->checkMemory();
				sleep(5);
				continue;
			}
			
			// How long since last retry?
			$sinceLast = time()-$deferred->lastRetry;
			$timeToRetry = $this->options['queuemanager']['retry_interval']-$sinceLast;
			
			// More idleing required?
			if ($timeToRetry > 0) { 
				$this->checkMemory();
				sleep(min(5,$timeToRetry)); // 5 seconds, or next retry interval, whichever comes first
				continue;
			}
			
			// Does the message still have retries left
			if ($deferred->retries <= $this->options['queuemanager']['retries']) { // Retry message delivery
				$this->queue->popLastDeferred();
				
				// Remove recipients that already got the message
				$msisdns = $this->queue->getMsisdnsForMessage($deferred->id);
				if (!empty($msisdns)) $deferred->recipients = array_diff($deferred->recipients,$msisdns);
				if (empty($deferred->recipients)) {
					$this->debug('Deferred message without valid recipients: '.$deferred->id);
				}
				
				// Re-attempt delivery
				$this->debug('Retry delivery of failed message: '.$deferred->id.' retry #'.$deferred->retries);
				$this->queue->produce(array($deferred));
				
			} else { // remove it
				syslog(LOG_WARNING,__FILE__.': Deferred message reached max retries, ID:'.$deferred->id);
				$this->debug('Deferred message reached max retries, ID:'.$deferred->id);
				$this->queue->popLastDeferred();
			}
		}
	}
}

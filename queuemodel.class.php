<?php

/**
 * Redis backend abstraction layer.
 * Using the phpredis extension this class will handle all operations with the redis backend.
 * 
 * Copyright (C) 2011 OnlineCity
 * Licensed under the MIT license, which can be read at: http://www.opensource.org/licenses/mit-license.php
 * @author hd@onlinecity.dk
 */
class QueueModel
{
	protected $redis;
	protected $key;
	protected $useIgBinary;
	protected $options;
	
	/**
	* Construct a new SmsSender worker
	* @param array $options
	* @throws RedisException
	*/
	public function __construct($options)
	{
		$this->redis = new Redis();
		$this->redis->connect($options['queue']['host'],$options['queue']['port'],$options['queue']['connect_timeout']);
		$this->key = $options['queue']['queuekey'];
		$this->useIgBinary = ($options['queue']['use_igbinary'] && function_exists('igbinary_serialize'));
		$this->options = $options;
	}
	
	/**
	 * Close connection to queue backend
	 */
	public function close()
	{
		$this->redis->close();
	}
	
	/**
	 * Produce one or more SMS'es (push to queue).
	 * Returns the length of the queue on success and false on failure.
	 * @param array $messages
	 * @return integer
	 */
	public function produce($messages)
	{
		$pipeline = $this->redis->multi(Redis::PIPELINE);
		foreach ($messages as $m) {
			$pipeline->lpush($this->key.':inactive',$this->serialize($m));
		}
		$replies = $pipeline->exec();
		return end($replies);
	}
	
	/**
	 * Consume a single SMS. Blocking with timeout.
	 * Timeout is specified in seconds.
	 * Returns false on timeout
	 * 
	 * @param integer $pid
	 * @param integer $timeout
	 * @return SmsMessage
	 */
	public function consume($pid,$timeout=5)
	{
		$m = $this->redis->brpoplpush($this->key.':inactive',$this->key.':active:'.$pid,$timeout);
		if ($m === false) return $m;
		return $this->unserialize($m);
	}
	
	/**
	 * Store SMSC ids with their SMS IDs
	 * 
	 * @param integer $smsId
	 * @param array $smscIds
	 */
	public function storeIds($smsId,array $smscIds,array $msisdns)
	{
		$retention = (int) $options['queue']['retention'];
		$pipeline = $this->redis->multi(Redis::PIPELINE);
		foreach ($smscIds as $i => $id) {
			$pipeline->sAdd($this->key.':ids:'.$smsId,$id);
			$pipeline->sAdd($this->key.':msisdns:'.$smsId,$msisdns[$i]);
			$pipeline->setex($this->key.':id:'.$id,3600*$retention,$smsId);
		}
		$pipeline->expire($this->key.':ids:'.$smsId,3600*$retention);
		$pipeline->expire($this->key.':msisdns:'.$smsId,3600*$retention);
		$replies = $pipeline->exec();
		return end($replies);
	}
	
	/**
	 * Get the matching sms ids for a bunch of SMSC ids
	 * @param array $smscIds
	 * @return array
	 */
	public function getSmsIds($smscIds)
	{
		$pipeline = $this->redis->multi(Redis::PIPELINE);
		foreach ($smscIds as $i => $id) {
			$pipeline->get($this->key.':id:'.$id);
		}
		$replies = $pipeline->exec();
		if (!$replies) return false;
		
		$smsids = array();
		foreach ($replies as $i => $reply) {
			if ($reply) $smsids[$smscIds[$i]] = $reply;
		}
		if (empty($smsids)) return false;
		return $smsids;
	}
	
	/**
	 * Store a bunch of  DeliveryReports
	 * @param DeliveryReport $dlr
	 */
	public function storeDlr(array $dlrs)
	{
		$pipeline = $this->redis->multi(Redis::PIPELINE);
		foreach ($dlrs as $dlr) {
			$d = call_user_func((($this->options['queue']['use_igbinary_for_dlr']) ? 'igbinary_serialize' : 'serialize'),$dlr);
			$pipeline->lPush($this->options['queue']['dlr_queue'],$d);
		}
		$replies = $pipeline->exec();
		return end($replies);
	}
	
	/**
	 * Defer delivery of a SMS.
	 * 
	 * @param integer $pid
	 * @param SmsMessage $message
	 */
	public function defer($pid, SmsMessage $message)
	{
		$m = $this->serialize($message);
		$this->redis->lRem($this->key.':active:'.$pid,$m);
		$this->redis->lPush($this->key.':deferred',$m);
	}
	
	/**
	 * Get MSISDNs for a sms.
	 * 
	 * @param integer $smsId
	 * @return array
	 */
	public function getMsisdnsForMessage($smsId)
	{
		return $this->redis->sMembers($this->key.':msisdns:'.$smsId);
	}
	
	/**
	 * Get the latest deferred message
	 * @return SmsMessage
	 */
	public function lastDeferred()
	{
		$m = $this->redis->lIndex($this->key.':deferred',-1);
		return $this->unserialize($m);
	}
	
	/**
	 * Remove (pop) the lastest deferred message
	 * @return SmsMessage
	 */
	public function popLastDeferred()
	{
		$m = $this->redis->rPop($this->key.':deferred');
		$m = $this->unserialize($m);
		return $m;
	}
	
	/**
	 * Ping the backend to keep our connection alive
	 */
	public function ping()
	{
		$this->redis->ping();
	}

	/**
	 * Shorthand for unserialize
	 * @param string $d
	 * @return mixed
	 */
	private function unserialize($d)
	{
		return call_user_func(($this->useIgBinary ? 'igbinary_unserialize' : 'unserialize'),$d);
	}
	
	/**
	 * Shorthand for serialize
	 * @param mixed $d
	 * @return string
	 */
	private function serialize($d)
	{
		return call_user_func(($this->useIgBinary ? 'igbinary_serialize' : 'serialize'),$d);
	}
}

class SmsMessage
{
	public $id;
	public $sender;
	public $message;
	public $recipients;
	public $retries;
	public $lastRetry;
	
	/**
	 * Create a new SMS Message to send
	 * 
	 * @param integer $id
	 * @param string $sender
	 * @param string $message
	 * @param array $recipients array of msisdns
	 */
	public function __construct($id, $sender, $message, $recipients)
	{
		$this->id = $id;
		$this->sender = $sender;
		$this->message = $message;
		$this->recipients = $recipients;
		$this->retries = 0;
		$this->lastRetry = null;
	}
}

class DeliveryReport implements Serializable
{
	public $providerId;
	public $messageId;
	public $msisdn;
	public $statusReceived;
	public $statusCode;
	public $errorCode;

	// Normal status codes
	const STATUS_DELIVERED = 1;
	const STATUS_BUFFERED = 2;
	const STATUS_ERROR = 3;
	const STATUS_EXPIRED = 4;

	// Extra status codes, not used often
	const STATUS_QUEUED = 5;
	const STATUS_INSUFFICIENT_CREDIT = 6;
	const STATUS_BLACKLISTED = 7;
	const STATUS_UNKNOWN_RECIPIENT = 8;
	const STATUS_PROVIDER_ERROR = 9;
	const STATUS_INVALID_SMS_ENCODING = 10;
	const STATUS_DELETED = 11;

	// Error codes
	const ERROR_UNKNOWN = 1;
	const ERROR_EXPIRED = 2;
	const ERROR_INSUFFICIENT_CREDIT = 3;
	const ERROR_BLACKLISTED = 4;
	const ERROR_UNKNOWN_RECIPIENT = 5;
	const ERROR_INVALID_SMS_ENCODING = 6;
	const ERROR_DELETED = 7;

	public function __construct($messageId, $msisdn, $statusReceived, $statusCode, $errorCode=null, $providerId =null)
	{
		$this->messageId = $messageId;
		$this->msisdn = $msisdn;
		$this->statusReceived = $statusReceived;
		$this->statusCode = $statusCode;
		$this->errorCode = $errorCode;
		$this->providerId = $providerId;
	}

	public function serialize()
	{
		return serialize(array($this->providerId, $this->messageId, $this->msisdn, $this->statusReceived, $this->statusCode, $this->errorCode));
	}

	public function unserialize($data)
	{
		list($this->providerId, $this->messageId, $this->msisdn, $this->statusReceived, $this->statusCode, $this->errorCode) = unserialize($data);
	}
}

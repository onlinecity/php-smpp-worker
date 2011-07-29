<?php
require_once 'queuemodel.class.php';
require_once 'smpp'.DIRECTORY_SEPARATOR.'sockettransport.class.php';
require_once 'smpp'.DIRECTORY_SEPARATOR.'smppclient.class.php';
require_once 'smpp'.DIRECTORY_SEPARATOR.'gsmencoder.class.php';

/**
 * SMS worker for sending SMSes.
 * Uses a blocking operation to wait for new SMS'es and sends them using SMPP in batches of 10. 
 * It will store the ids from the SMSC for every batch.
 * 
 * Copyright (C) 2011 OnlineCity
 * Licensed under the MIT license, which can be read at: http://www.opensource.org/licenses/mit-license.php
 * @author hd@onlinecity.dk
 */
class SmsSender
{
	protected $options;
	protected $transport;
	protected $client;
	protected $queue;
	protected $debug;

	private $lastEnquireLink;

	/**
	 * Construct a new SmsSender worker
	 * @param array $options
	 */
	public function __construct($options)
	{
		$this->options = $options;
		$this->debug = $this->options['sender']['debug'];
		pcntl_signal(SIGTERM, array($this,"disconnect"), true);
		
		gc_enable();
	}
	
	public function disconnect()
	{
		// Close queue
		if (isset($this->queue)) $this->queue->close();
		
		// Close transport
		if (isset($this->transport) && $this->transport->isOpen()) {
			if (isset($this->client)) {
				$this->client->close();
			} else {
				$this->transport->close();
			}
		}
		exit();
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
	 * Connect to the queue backend.
	 * Construct and open the transport
	 * Construct client and bind as transmitter.
	 *
	 */
	protected function connect()
	{
		// Init queue
		$this->queue = new QueueModel($this->options);

		// Set some transport defaults first
		SocketTransport::$defaultDebug = $this->debug;
		SocketTransport::$forceIpv4 = $this->options['connection']['forceIpv6'];
		SocketTransport::$forceIpv4 = $this->options['connection']['forceIpv4'];

		// Construct the transport
		$h = $this->options['connection']['hosts'];
		$p = $this->options['connection']['ports'];
		$d = $this->options['general']['debug_handler'];

		$this->transport = new SocketTransport($h,$p,false,$d);

		// Set connection timeout and open connnection
		$this->transport->setRecvTimeout($this->options['sender']['recv_timeout']);
		$this->transport->setSendTimeout($this->options['sender']['connect_timeout']);
		$this->transport->open();
		$this->transport->setSendTimeout($this->options['sender']['send_timeout']);

		// Construct client and login
		$this->client = new SmppClient($this->transport, $this->options['general']['protocol_debug_handler']);
		$this->client->debug = $this->options['sender']['smpp_debug'];
		$this->client->bindTransmitter($this->options['connection']['login'], $this->options['connection']['password']);
		
		// Set other client options
		SmppClient::$sms_registered_delivery_flag = ($this->options['connection']['registered_delivery']) ? SMPP::REG_DELIVERY_SMSC_BOTH : SMPP::REG_DELIVERY_NO;
		SmppClient::$sms_use_msg_payload_for_csms = $this->options['connection']['use_msg_payload'];
		SmppClient::$sms_null_terminate_octetstrings = $this->options['connection']['null_terminate_octetstrings'];
	}
	
	/**
	 * Keep our connections alive.
	 * Send enquire link to SMSC, respond to any enquire links from SMSC and ping the queue server
	 */
	protected function ping()
	{
		$this->queue->ping();
		$this->client->enquireLink();
		$this->client->respondEnquireLink();
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
			$this->disconnect();
		}
	}

	/**
	 * This workers main loop
	 */
	public function run()
	{
		$this->connect();
		
		$this->lastEnquireLink = 0;

		try {
			
			while (true) {
				// commit suicide if the parent process no longer exists
				if (posix_getppid() == 1) {
					$this->disconnect();
					exit();
				}
					
				// Make sure to send enquire link periodically to keep the link alive
				if (time()-$this->lastEnquireLink >= $this->options['connection']['enquire_link_timeout']) {
					$this->ping();
					$this->lastEnquireLink = time();
				}
				
				// Queue->consume will block until there is something to do, or a 5 sec timeout is reached
				$sms = $this->queue->consume(getmypid(),5);
				if ($sms === false || is_null($sms)) { // idle
					$this->checkMemory();
					continue;
				}
				
				// Prepare message
				$encoded = GsmEncoder::utf8_to_gsm0338($sms->message);
				$encSender = utf8_decode($sms->sender);
				if (strlen($encSender)>11) $encSender = substr($encSender,0,11); // truncate
				
				// Contruct SMPP Address objects
				if (!ctype_digit($sms->sender)) {
					$sender = new SmppAddress($encSender,SMPP::TON_ALPHANUMERIC);
				} else if ($sms->sender < 10000) {
					$sender = new SmppAddress($sms->sender,SMPP::TON_NATIONAL,SMPP::NPI_E164);
				} else {
					$sender = new SmppAddress($sms->sender,SMPP::TON_INTERNATIONAL,SMPP::NPI_E164);
				}
				
				// Send message
				$ids = array();
				$msisdns = array();
				try {
					$i = 0;
					foreach ($sms->recipients as $number) {
						$address = new SmppAddress($number,SMPP::TON_INTERNATIONAL,SMPP::NPI_E164);
						$ids[] = $this->client->sendSMS($sender, $address, $encoded);
						$msisdns[] = $number;
				
						if (++$i % 10 == 0) {
							// relay back for every 10 SMSes
							$this->queue->storeIds($sms->id, $ids, $msisdns);
							
							// Pretty debug output
							if ($this->debug) {
								$s = 'Sent SMS: '.$sms->id.' with ids:';
								foreach ($ids as $n => $id) {
									if ($n % 2 == 0) $s .= "\n";
									$s .= "\t".$msisdns[$n].":".$id;
								}
								$this->debug($s);
							}
							$ids = array();
							$msisdns = array();
						}
					}
				} catch (\Exception $e) {
					if (!empty($ids)) {
						// make sure to report any partial progress back
						$this->queue->storeIds($sms->id, $ids, $msisdns);
						$this->debug('SMS with partial progress: '.$sms->id.' with ids: '.implode(', ',$ids));
					}
					$this->debug('Deferring SMS id:'.$sms->id);
					$sms->retries++;
					$sms->lastRetry = time();
					$this->queue->defer(getmypid(), $sms);
					throw $e; // rethrow
				}
				
				if (!empty($ids)) {
					$this->queue->storeIds($sms->id, $ids, $msisdns);
					
					// Pretty debug output
					if ($this->debug) {
						$s = 'Sent SMS: '.$sms->id.' with ids:';
						foreach ($ids as $n => $id) {
							if ($n % 2 == 0) $s .= "\n";
							$s .= "\t".$msisdns[$n].":".$id;
						}
						$this->debug($s);
					}
				}
			}
		} catch (Exception $e) {
			$this->debug('Caught '.get_class($e).': '.$e->getMessage()."\n\t".$e->getTraceAsString());
			$this->disconnect();
		}
	}
}
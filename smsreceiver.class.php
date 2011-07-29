<?php
require_once 'queuemodel.class.php';
require_once 'smpp'.DIRECTORY_SEPARATOR.'sockettransport.class.php';
require_once 'smpp'.DIRECTORY_SEPARATOR.'smppclient.class.php';
require_once 'smpp'.DIRECTORY_SEPARATOR.'gsmencoder.class.php';

/**
 * Receiver worker, which will wait for new SMS'es on the socket.
 * Any received delivery receipts will be converted to a special DeliveryReport object and stored.
 * Delivery receipts are processed in batches of 500, or when idle.
 * 
 * Copyright (C) 2011 OnlineCity
 * Licensed under the MIT license, which can be read at: http://www.opensource.org/licenses/mit-license.php
 * @author hd@onlinecity.dk
 */
class SmsReceiver
{
	protected $options;
	protected $transport;
	protected $client;
	protected $queue;
	protected $debug;
	protected $dlrs;

	private $lastEnquireLink;

	/**
	 * Construct a new SmsReceiver worker
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
		// Process remaining DLRs
		if (isset($this->queue) && !empty($this->dlrs)) {
			$this->processDlrs();
		}

		// Close queue
		if (isset($this->queue)) $this->queue->close();

		// Disconnect transport
		if (isset($this->transport) && $this->transport->isOpen()) {
			if (isset($this->client)) {
				try {
					$this->client->close();
				} catch (Exception $e) {
					$this->transport->close();
				}
			} else {
				$this->transport->close();
			}
		}

		// End execution
		exit();
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
		$this->transport->setRecvTimeout($this->options['receiver']['connect_timeout']);
		$this->transport->setSendTimeout($this->options['receiver']['connect_timeout']);
		$this->transport->open();
		$this->transport->setSendTimeout($this->options['receiver']['send_timeout']);
		$this->transport->setRecvTimeout(5000); // wait for 5 seconds for data

		// Construct client and login
		$this->client = new SmppClient($this->transport, $this->options['general']['protocol_debug_handler']);
		$this->client->debug = $this->options['receiver']['smpp_debug'];
		$this->client->bindReceiver($this->options['connection']['login'], $this->options['connection']['password']);
			
		// Set other client options
		SmppClient::$sms_null_terminate_octetstrings = $this->options['connection']['null_terminate_octetstrings'];
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
	 * Process the queue of delivery reports/receipts
	 * Match up the SMSC ID with the original SMS ID.
	 * Convert the receipt to a DeliveryReport object, and store it.
	 *
	 */
	protected function processDlrs()
	{
		$smscIds = array_keys($this->dlrs);
		$smsIds = $this->queue->getSmsIds($smscIds);
		if (!$smsIds) return;
		
		$reports = array();
		
		// Iterate results and convert them into DeliveryReports
		foreach ($smsIds as $smscId => $smsId) {
			$dlr = $this->dlrs[$smscId]; /* @var $dlr SmppDeliveryReceipt */
				
			// Construct DeliveryReport object
			$msisdn = $dlr->source->value;
			switch ($dlr->stat) {
				case 'DELIVRD':
					$statusCode = DeliveryReport::STATUS_DELIVERED;
					$errorCode = null;
					break;
				case 'EXPIRED':
					$statusCode = DeliveryReport::STATUS_EXPIRED;
					$errorCode = DeliveryReport::ERROR_EXPIRED;
					break;
				case 'DELETED':
					$statusCode = DeliveryReport::STATUS_EXPIRED;
					$errorCode = DeliveryReport::ERROR_DELETED;
					break;
				case 'ACCEPTD':
					$statusCode = DeliveryReport::STATUS_BUFFERED;
					$errorCode = null;
					break;
				case 'REJECTD':
					$statusCode = DeliveryReport::STATUS_ERROR;
					$errorCode = DeliveryReport::ERROR_UNKNOWN_RECIPIENT;
					break;
				case 'UNKNOWN':
				case 'UNDELIV':
				default:
					$statusCode = DeliveryReport::STATUS_ERROR;
					$errorCode = DeliveryReport::ERROR_UNKNOWN;
					break;
			}
			$report = new DeliveryReport($smsId, $msisdn, $dlr->doneDate, $statusCode, $errorCode, $this->options['receiver']['dlr_provider_id']);

			// Store the Delivery Report
			$reports[$smscId] = $report;
		}
		
		// Push the reports to the queue
		$this->queue->storeDlr($reports);
		foreach ($reports as $smscId => $report) {
			unset($this->dlrs[$smscId]);
		}
		unset($reports);
		
		// Remove timed out dlrs
		foreach ($this->dlrs as $dlrId => $dlr) { /* @var $dlr SmppDeliveryReceipt */
			// If SMS ID not found within an hour, remove it
			if ($dlr->doneDate < (time()-3600)) {
				$this->debug('Could not match SMSC ID: '.$dlr->id.' to a SMS ID within an hour. Giving up.');
				unset($this->dlrs[$dlrId]);
				continue;
			}
		}
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
	 * This service's main loop
	 */
	public function run()
	{
		$this->connect();

		$this->lastEnquireLink = 0;

		try {

			$i = 0;
				
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

				// Make sure to process DLRs for every 500 DLRs received
				if ($i % 500 == 0) {
					if (!empty($this->dlrs)) $this->processDlrs();
					$this->checkMemory(); // do garbage collect, and check the memory limit
				}

				// SMPP will block until there is something to do, or a 5 sec timeout is reached
				$sms = $this->client->readSMS();
				if ($sms === false) {
					// idle
					if (!empty($this->dlrs)) $this->processDlrs(); // use this idle time to process dlrs
					$this->checkMemory();
					continue;
				}

				$i++; // keep track of how many DLRs are received

				if (!$sms instanceof SmppDeliveryReceipt) {
					$this->debug('Received SMS instead of DeliveryReceipt, this should not happen. SMS:'.var_export($sms,true));
					continue;
				}

				// Done dates from SMSC is sometimes out of sync, override them?
				if ($this->options['receiver']['override_dlr_donedate']) {
					$sms->doneDate = time();
				}

				// Push the DLR to queue
				$this->dlrs[$sms->id] = $sms;
			}
		} catch (Exception $e) {
			$this->debug('Caught '.get_class($e).': '.$e->getMessage()."\n\t".$e->getTraceAsString());
			$this->disconnect();
		}
	}
}
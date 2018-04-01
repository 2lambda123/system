<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2017 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Processor for Credit Guard files.
 * @package  Billing
 * @since    5.7
 */
class Billrun_Processor_CGfeedback extends Billrun_Processor {

	/**
	 *
	 * @var string
	 */

	protected static $type = 'CGfeedback';

	protected $structConfig;
	protected $headerStructure;
	protected $dataStructure;
	protected $deals_num;
	protected $bills;

	
	public function __construct($options) {
		parent::__construct($options);
		$this->bills = Billrun_Factory::db()->billsCollection();
	}
	
	/**
	 * @see Billrun_Plugin_Interface_IProcessor::processData
	 */
	protected function processLines() {
		$this->loadConfig(Billrun_Factory::config()->getConfigValue(self::$type . '.config_path'));		
		$parser = $this->getParser();
		$parser->setHeaderStructure($this->headerStructure);
		$parser->setDataStructure($this->dataStructure);
		$parser->parse($this->fileHandler);
		$parsedData = $parser->getDataRows();
		$rowCount = 0;

		foreach ($parsedData as $line) {
			$row = $this->getBillRunLine($line);
			if (!$row){
				return false;
			}
			$row['amount'] = $line['amount'];
			$row['transaction_id'] = $line['deal_id'];
			$row['ret_code'] = $line['deal_status'];
			$row['row_number'] = ++$rowCount;
			$this->addDataRow($row);
		}
		$this->updateData();
		return true;		
	}

	protected function getBillRunLine($rawLine) {
		$row['uf'] = $rawLine;

		$datetime = $this->getRowDateTime($row['uf']['date']);
		if (!$datetime) {
			Billrun_Factory::log('Cannot set urt for line. Data: ' . print_R($row, 1), Zend_Log::ALERT);
			return false;
		}
		$row['eurt'] = $row['urt'] = new MongoDate($datetime->format('U'));	
		$row['stamp'] = md5(serialize($row));
		$row['type'] = static::$type;
		$row['source'] = self::$type;
		$row['file'] = basename($this->filePath);
		$row['log_stamp'] = $this->getFileStamp();
		$row['process_time'] = new MongoDate();
		return $row;
	}

	protected function updateData() {
		$data = $this->getData();
		foreach ($data['data'] as $row) {
			$bill = Billrun_Bill_Payment::getInstanceByid($row['transaction_id']);
			if (is_null($bill)) {
				Billrun_Factory::log('Unknown transaction ' . $row['transaction_id'], Zend_Log::ALERT);
				continue;
			}	
			$paymentResponse = $this->getPaymentResponse($row);
			Billrun_Bill_Payment::updateAccordingToStatus($paymentResponse, $bill, 'CreditGuard');
			if ($paymentResponse['stage'] == 'Completed') {
				$this->updateInvoicePaidStatus($bill);
			}
		}
	}

	/**
	 * the structure configuration
	 * @param type $path
	 */
	protected function loadConfig($path) {
		$this->structConfig = (new Yaf_Config_Ini($path))->toArray();
		$this->headerStructure = $this->structConfig['header'];
		$this->dataStructure = $this->structConfig['data'];
	}
	
	
	protected function isValidTransaction($row){
		if ($row['ret_code'] == '000') { // 000 - Good Deal
			return true;
		} else{
			return false;
		}
	}
	
	protected function defineEmailToSend($bill, $row, $rejections) {
		return array(
			'aid' => $bill->getAccountNo(),
			'amount' => $bill->getAmount(),
			'date' => $row['process_time'],
			'reason' => $rejections[$row['ret_code']],
		);
	}
	
	protected function sendEmail($emailsToSend) {
		if ($emailsToSend) {
			$subscriber = Billrun_Factory::subscriber();
			$data = array(
				'operation' => 'CGfeedback',
				'entities' => $emailsToSend,
			);
			$emailsResult = $subscriber->sendBillingOperationsNotifications($data);
			if (isset($emailsResult['status']) && $emailsResult['status'] == 1) {
				Billrun_Factory::log()->log('CG Deal rejection: ' . $emailsResult['emails_sent'] . ' emails queued for sending.', Zend_Log::INFO);
			} else {
				Billrun_Factory::log()->log('CRM returned with error when trying to send emails (CG Deal rejection)', Zend_Log::ALERT);
			}
		}
	}
	
	protected function getRowDateTime($dateStr) {
		$datetime = new DateTime();
		$date = $datetime->createFromFormat('ymdHis', $dateStr);
		return $date;
	}
	
	protected function store() {
		if (!isset($this->data['data'])) {
			Billrun_Factory::log('Got empty data from file  : ' . basename($this->filePath), Zend_Log::ERR);
			return false;
		}

		$lines = Billrun_Factory::db()->linesCollection();
		Billrun_Factory::log("Store data of file " . basename($this->filePath) . " with " . count($this->data['data']) . " lines", Zend_Log::INFO);

		if ($this->bulkInsert) {
			settype($this->bulkInsert, 'int');
			if (!$this->bulkAddToCollection($lines)) {
				return false;
			}
		} else {
			$this->addToCollection($lines);
		}

		Billrun_Factory::log("Finished storing data of file " . basename($this->filePath), Zend_Log::INFO);
		return true;
	}
	
	protected function getPaymentResponse($row) {
		$stage = 'Rejected';
		if ($this->isValidTransaction($row)) {
			$stage = 'Completed';
		}

		return array('status' => $row['ret_code'], 'stage' => $stage);
	}
	
	protected function updateInvoicePaidStatus($rec) {
		$recId = $rec->getId();
		$invoiceId = $rec->getInvoiceIdFromReceipt();
		$query = array(
			'invoice_id' => $invoiceId
		);
		$update = array(
			'$set' => array(
				'paid' => '1',
			),
			'$pull' => array(
				'waiting_payments' => array(
					'$in' => array($recId)
				)
			)
		);
		$this->bills->update($query, $update);
	}

}

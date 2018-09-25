<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * BillRun Payment class
 *
 * @package  Billrun
 * @since    5.0
 */
abstract class Billrun_Bill_Payment extends Billrun_Bill {
	
	use Billrun_Traits_Api_OperationsLock;
	/**
	 *
	 * @var string
	 */
	protected $type = 'rec';

	/**
	 * Payment method
	 * @var string
	 */
	protected $method;

	/**
	 * Payment direction (fc - from customer, tc - to customer)
	 * @var string
	 */
	protected $dir = 'fc';

	/**
	 * Optional fields to be saved to the payment. For some payment methods they are mandatory.
	 * @var array
	 */
	protected $optionalFields = array('payer_name', 'aaddress', 'azip', 'acity', 'IBAN', 'bank_name', 'BIC', 'cancel', 'RUM', 'correction', 'rejection', 'rejected', 'original_txid', 'rejection_code', 'source', 'pays', 'country', 'paid_by', 'vendor_response');

	protected static $aids;
	/**
	 * 
	 * @param type $options
	 */
	public function __construct($options) {
		$this->billsColl = Billrun_Factory::db()->billsCollection();
		$direction = 'fc';
		if (isset($options['_id'])) {
			$this->data = new Mongodloid_Entity($options, $this->billsColl);
		} elseif (isset($options['aid'], $options['amount'])) {
			if (!is_numeric($options['amount']) || $options['amount'] <= 0 || !is_numeric($options['aid'])) {
				throw new Exception('Billrun_Bill_Payment: Wrong input. Was: Customer: ' . $options['aid'] . ', amount: ' . $options['amount'] . '.');
			}
			$this->data = new Mongodloid_Entity($this->billsColl);
			if (isset($options['dir'])) {
				$direction = $options['dir'];
			}
			$this->setDir($direction);
			$this->data['method'] = $this->method;
			$this->data['aid'] = intval($options['aid']);
			$this->data['type'] = $this->type;
			$this->data['amount'] = floatval($options['amount']);
			if (isset($options['due'])) {
				$this->data['due'] = $options['due'];
			} else {
				$this->data['due'] = $this->getDir() == 'fc' ? -$this->data['amount'] : $this->data['amount'];
			}
			if (isset($options['gateway_details'])){
				$this->data['gateway_details'] = $options['gateway_details'];
			}
			if (isset($options['pays']['inv'])) {
				foreach ($options['pays']['inv'] as $invoiceId => $amount) {
					$options['pays']['inv'][$invoiceId] = floatval($amount);
				}
			}
			if (isset($options['paid_by']['inv'])) {
				foreach ($options['paid_by']['inv'] as $invId => $credit) {
					$options['paid_by']['inv'][$invId] = floatval($credit);
				}
			}
			
			$this->data['urt'] = new MongoDate();

			foreach ($this->optionalFields as $optionalField) {
				if (isset($options[$optionalField])) {
					$this->data[$optionalField] = $options[$optionalField];
				}
			}
		} else {
			throw new Exception('Billrun_Bill_Payment: Insufficient options supplied.');
		}
		parent::__construct($options);
	}

	/**
	 * Save the payment to bills collection
	 * @param type $param
	 * @return type
	 */
	public function save() {
//		$this->coverInvoices();
		if (!isset($this->data['txid'])) {
			$this->setTxid();
		}
		return parent::save();
	}

	protected function setTxid($txid = NULL) {
		if ($txid) {
			$this->data['txid'] = $txid;
		} else {
			$this->data['_id'] = new MongoId();
			$this->data->createAutoInc('txid');
			$this->data['txid'] = str_pad($this->data['txid'], 13, '0', STR_PAD_LEFT);
		}
	}

	/**
	 * Insert new payments to bills collection
	 * @param array $payments
	 */
	public static function savePayments($payments) {
		if ($payments) {
			foreach ($payments as $payment) {
				if (!$payment->getId()) {
					$payment->setTxid();
				}
				$rawPayments[] = $payment->getRawData();
			}
			$options = array(
				'w' => 1,
				'ordered' => FALSE,
			);
			return Billrun_Factory::db()->billsCollection()->batchInsert($rawPayments, $options);
		}
		return NULL;
	}

	/**
	 * 
	 * @param string $id
	 * @return Billrun_Bill_Payment
	 */
	public static function getInstanceByid($id) {
		$data = Billrun_Factory::db()->billsCollection()->query('txid', $id)->cursor()->current();
		if ($data->isEmpty()) {
			return NULL;
		}
		return self::getInstanceByData($data);
	}

	/**
	 * 
	 * @param Mongodloid_Entity|array $data
	 * @return Billrun_Bill_Payment
	 */
	public static function getInstanceByData($data) {
		$className = self::getClassByPaymentMethod($data['method']);
		$rawData = is_array($data) ? $data : $data->getRawData();
		$instance = new $className($rawData);
		$instance->setRawData($rawData);
		return $instance;
	}

	/**
	 * 
	 * @return Billrun_Bill_Payment
	 */
	public function getCancellationPayment() {
		$className = Billrun_Bill_Payment::getClassByPaymentMethod($this->getPaymentMethod());
		$rawData = $this->getRawData();
		unset($rawData['_id']);
		$rawData['due'] = $rawData['due'] * -1;
		$rawData['cancel'] = $this->getId();
		return new $className($rawData);
	}

	public static function getClassByPaymentMethod($paymentMethod) {
		return 'Billrun_Bill_Payment_' . str_replace(' ', '', ucwords(str_replace('_', ' ', $paymentMethod)));
	}

	public function getPaymentMethod() {
		return $this->method;
	}

	/**
	 * 
	 * @param array $rejection
	 * @return Billrun_Bill_Payment
	 */
	public function getRejectionPayment($response) {
		$className = Billrun_Bill_Payment::getClassByPaymentMethod($this->getPaymentMethod());
		$rawData = $this->getRawData();
		unset($rawData['_id']);
		$rawData['original_txid'] = $this->getId();
		$rawData['due'] = $rawData['due'] * -1;
		$rawData['rejection'] = TRUE;
		$rawData['rejection_code'] = $response['status'];
		if (isset($response['extra_params'])) {
			$rawData['vendor_response'] = $response['extra_params'];
		}
		return new $className($rawData);
	}

	public function getId() {
		if (isset($this->data['txid'])) {
			return $this->data['txid'];
		}
		return NULL;
	}

	public function getRejectionCode() {
		if ($this->isRejection() && isset($this->data['rejection_code'])) {
			return $this->data['rejection_code'];
		}
		return NULL;
	}

	public function getCancellationId() {
		if (isset($this->data['cancel'])) {
			return $this->data['cancel'];
		}
		return NULL;
	}

	public function getDir() {
		return $this->data['dir'];
	}

	/**
	 * Set the direction of the payment (fc / tc)
	 * @param string $dir
	 */
	protected function setDir($direction = 'fc') {
		if (in_array($direction, array('fc', 'tc'))) {
			$this->data['dir'] = $direction;
		} else {
			throw new Exception('direction could be either \'fc\' or \'tc\'');
		}
	}

	/**
	 * Get non-rejected payments
	 * @param type $aid
	 * @param type $dir
	 * @param type $methods
	 * @param type $paymentDate
	 * @param type $amount
	 * @return type
	 */
	public static function getPayments($aid = null, $dir = array('fc'), $methods = array(), $to = null, $from = null, $amount = null, $includeRejected = false, $includeCancelled = false) {
		if (!$includeRejected) {
			$query['rejected'] = array(// rejected payments
				'$ne' => TRUE,
			);
			$query['rejection'] = array(// rejecting payments
				'$ne' => TRUE,
			);
		}
		if (!$includeCancelled) {
			$query['cancelled'] = array(// cancelled payments
				'$ne' => TRUE,
			);
			$query['cancel'] = array(// cancelling payments
				'$exists' => FALSE,
			);
		}
		if (!is_null($aid)) {
			$query['aid'] = $aid;
		}
		if ($dir) {
			$query['dir'] = array(
				'$in' => $dir
			);
		}

		if ($methods) {
			$query['method'] = array(
				'$in' => $methods,
			);
		}
		if ($to && $from) {
			$query['urt'] = array(
				'$gte' => new MongoDate(strtotime($from . ' 00:00:00')),
				'$lte' => new MongoDate(strtotime($to . ' 23:59:59')),
			);
		}
		if (!is_null($amount)) {
			$query['amount'] = $amount;
		}
		return static::queryPayments($query);
	}

	/**
	 * Run a query  on thepayments in the  bills  collection.
	 * @param array $query
	 * @return type
	 */
	public static function queryPayments($query = array(), $sort = array('urt' => -1)) {
		$billsColl = Billrun_Factory::db()->billsCollection();
		$query['type'] = 'rec';
		return iterator_to_array($billsColl->query($query)->cursor()->setRawReturn(true)->sort($sort), FALSE);
	}

	public static function getRejections() {
		$rejection_codesColl = Billrun_Factory::db()->rejection_codesCollection();
		$rejections = iterator_to_array($rejection_codesColl->find(array()), FALSE);
		return array_combine(array_map(function ($rejection) {
				return $rejection['code'];
			}, $rejections), $rejections);
	}

	/**
	 * Mark a payment as rejected to avoid rejecting it again
	 * @return boolean
	 * @todo Make rejections using transactions. This approach is not fail-safe.
	 */
	public function markRejected() {
		$this->data['rejected'] = true;
		$this->data['waiting_for_confirmation'] = false;
		$this->detachPaidBills();
		$this->detachPayingBills();
		$this->save();
	}

	/**
	 * Find whether a payment has been rejected or not
	 * @return boolean
	 */
	public function isRejected() {
		return isset($this->data['rejected']) && $this->data['rejected'];
	}

	/**
	 * Find whether a payment is a rejection of an existing payment
	 * @return boolean
	 */
	public function isRejection() {
		return isset($this->data['rejection']) && $this->data['rejection'];
	}

	/**
	 * 
	 * @param type $query
	 * @return boolean
	 */
	public static function removePayments($query = array()) {
		if ($query) {
			$payments = static::queryPayments($query);
			if ($payments) {
				$res = Billrun_Factory::db()->billsCollection()->remove(array_merge($query, array('type' => 'rec')));
				if (isset($res['ok'], $res['n']) && $res['n']) {
					foreach ($payments as $payment) {
						$paymentObj = static::getInstanceByData($payment);
						$paymentObj->detachPaidBills();
					}
				} else {
					return FALSE;
				}
			}
		}
		return TRUE;
	}

	public function markCancelled() {
		$this->data['cancelled'] = true;
		return $this;
	}

	public function getCountry() {
		return isset($this->data['country']) ? $this->data['country'] : '';
	}

	/**
	 * Find whether a payment has been cancelled or not
	 * @return boolean
	 */
	public function isCancelled() {
		return isset($this->data['cancelled']) && $this->data['cancelled'];
	}

	/**
	 * Find whether a payment is a cancellation of an existing payment
	 * @return boolean
	 */
	public function isCancellation() {
		return isset($this->data['cancel']);
	}

	/**
	 * Update payment status
	 * @since 5.0
	 */
	public function updateConfirmation() {
		$this->data['waiting_for_confirmation'] = false;
		$this->data['confirmation_time'] = new MongoDate();
		$this->save();
	}

	/**
	 * Checks the status of the payment.
	 * 
	 * @return boolean - true if the payment is still not got through.
	 */
	public function isWaiting() {
		if (isset($this->data['waiting_for_confirmation'])){
			$status = $this->data['waiting_for_confirmation'];
		}
		return is_null($status) ? false : $status;
	}

	/**
	 * Sets to true if the payment not yet approved.
	 * 
	 */
	public function setConfirmationStatus($status) {
		$this->data['waiting_for_confirmation'] = $status;
	}

	/**
	 * Saves the response from the gateway about the status of the payment.
	 * 
	 */
	public function setPaymentStatus($response, $gatewayName) {
		$vendorResponse = array('name' => $gatewayName, 'status' => $response['status']);
		$this->data['last_checked_pending'] = new MongoDate();
		$extraParams = isset($response['extra_params']) ? $response['extra_params'] : array();	
		$vendorResponse = array_merge($vendorResponse, $extraParams);
		$this->data['vendor_response'] = $vendorResponse;
		$this->save();
	}

	/**
	 * Saves the current time that represents the check to see if the a payment is pending.
	 * 
	 */
	public function updateLastPendingCheck() {
		$this->data['last_checked_pending'] = new MongoDate();
		$this->save();
	}
	
		/**
	 * Load payments with status pending and that their status had not been checked for some time. 
	 * 
	 */
	public static function loadPending() {
		$lastTimeChecked = Billrun_Factory::config()->getConfigValue('PaymentGateways.orphan_check_time');
		$paymentsOrphan = new MongoDate(strtotime('-' . $lastTimeChecked, time()));
		$query = array(
			'waiting_for_confirmation' => true,
			'last_checked_pending' => array('$lte' => $paymentsOrphan)
		);
		if (!empty(self::$aids)) {
			$query['aid'] = array('$in' => self::$aids);
		}	
		$payments = Billrun_Bill_Payment::queryPayments($query);
		$res = array();
		foreach ($payments as $payment) {
			$res[] = Billrun_Bill_Payment::getInstanceByData($payment);
		}
		
		return $res;
	}
	
	/**
	 * Responsible for paying payments and classifying payments responses: completed, pending or rejected.
	 * 
	 * @param array $chargeOptions - Options regarding charge operation.
	 *
	 */
	public static function makePayment($chargeOptions) {
		if (!empty($chargeOptions['aids'])) {
			self::$aids = Billrun_Util::verify_array($chargeOptions['aids'], 'int');
		}
		$size = !empty($chargeOptions['size']) ? $chargeOptions['size'] : 100;
		$page = !empty($chargeOptions['page']) ? $chargeOptions['page'] : 0;
		$filtersQuery = self::buildFilterQuery($chargeOptions);
		if (empty($filtersQuery) && !empty($chargeOptions)) {
			throw new Exception("Can't Charge, wrong input");
		}
		$payMode = isset($chargeOptions['pay_mode']) ? $chargeOptions['pay_mode'] : 'total_debt';
		if (!static::lock()) {
			Billrun_Factory::log("Charging is already running", Zend_Log::NOTICE);
			return;
		}
		$customersAids = Billrun_Factory::db()->billsCollection()->distinct('aid', $filtersQuery);
		$involvedAccounts = array();
		$options = array('collect' => true, 'payment_gateway' => TRUE);
		
		$query = Billrun_Utils_Mongo::getDateBoundQuery();
		$query['aid'] = array(
			'$in' => $customersAids
		);
		$query['type'] = "account";
		$subscribers = Billrun_Factory::db()->subscribersCollection()->query($query)->cursor();
		foreach ($subscribers as $subscriber) {
			$subscribers_in_array[$subscriber['aid']] = $subscriber;
		}
		foreach ($customersAids as $customerAid) {
			$accountIdQuery = self::buildFilterQuery(array('aids' => array($customerAid)));
			$filtersQuery['$and'] = array($accountIdQuery);
			$billsDetails = iterator_to_array(Billrun_PaymentGateway::getBillsAggregateValues($filtersQuery, $payMode, $page, $size));
			foreach ($billsDetails as $billDetails) {
				$paymentParams = array();
				$subscriber = $subscribers_in_array[$billDetails['aid']];
				$gatewayDetails = $subscriber['payment_gateway']['active'];
				if (!Billrun_PaymentGateway::isValidGatewayStructure($gatewayDetails)) {
					Billrun_Factory::log("Non valid payment gateway for aid = " . $billDetails['aid'], Zend_Log::ALERT);
					continue;
				}
				if (!empty($chargeOptions['invoices'])){
					if (is_null($billDetails['left_to_pay']) && is_null($billDetails['left'])) {
						Billrun_Factory::log("Can't pay! left and left_to_pay fields are missing, Account id: " . $billDetails['aid'] . ", Invoice_id: " . $billDetails['invoice_id'], Zend_Log::ALERT);
						continue;
					} else if (!is_null($billDetails['left_to_pay'])) {
						$paymentParams['amount'] = $gatewayDetails['amount'] = $billDetails['left_to_pay'];
					} else if (!is_null($billDetails['left'])) {
						$paymentParams['amount'] = $billDetails['left'];
						$gatewayDetails['amount'] = -$billDetails['left'];
					}
					if ($billDetails['due'] > 0) {
						$paymentParams['pays']['inv'][$billDetails['invoice_id']] = $paymentParams['amount'];
					} else {
						$paymentParams['paid_by']['inv'][$billDetails['invoice_id']] = $paymentParams['amount'];
					}
				} else {
					$paymentParams['amount'] = abs($billDetails['due']);
					$gatewayDetails['amount'] = $billDetails['due'];
				}
				if (Billrun_Util::isEqual($paymentParams['amount'], 0, Billrun_Bill::precision)) {
					continue;
				}
				$involvedAccounts[] = $paymentParams['aid'] = $billDetails['aid'];
				$paymentParams['billrun_key'] = $billDetails['billrun_key'];
				$gatewayDetails['currency'] = !empty($billDetails['currency']) ? $billDetails['currency'] : Billrun_Factory::config()->getConfigValue('pricing.currency');
				$gatewayName = $gatewayDetails['name'];
				$paymentParams['gateway_details'] = $gatewayDetails;
				if ($billDetails['due'] < 0) {
					$paymentParams['dir'] = 'tc';
				} else {
					$paymentParams['dir'] = 'fc';
				}
				if ((self::isChargeMode($chargeOptions) && $gatewayDetails['amount'] < 0) || (self::isRefundMode($chargeOptions) && $gatewayDetails['amount'] > 0)) {
					continue;
				}
				if ($gatewayDetails['amount'] > 0) {
					Billrun_Factory::log("Charging account " . $billDetails['aid'] . ". Amount: " . $paymentParams['amount'], Zend_Log::INFO);
				} else {
					Billrun_Factory::log("Refunding account " . $billDetails['aid'] . ". Amount: " . $paymentParams['amount'], Zend_Log::INFO);
				}
				Billrun_Factory::log("Starting to pay bills", Zend_Log::INFO);
				try {
					$paymentResponse = Billrun_Bill::pay($billDetails['payment_method'], array($paymentParams), $options);
				} catch (Exception $e) {
					Billrun_Factory::log($e->getMessage(), Zend_Log::ALERT);
					continue;
				}
				if (isset($paymentResponse['response']['status']) && $paymentResponse['response']['status'] === '000') {
					if ($gatewayDetails['amount'] > 0) {
						Billrun_Factory::log("Successful charging of account " . $billDetails['aid'] . ". Amount: " . $paymentParams['amount'], Zend_Log::INFO);
					} else {
						Billrun_Factory::log("Successful refunding of account " . $billDetails['aid'] . ". Amount: " . $paymentParams['amount'], Zend_Log::INFO);
					}
				}
				self::updateAccordingToStatus($paymentResponse['response'], $paymentResponse['payment'][0], $gatewayName);		
			}
		}
		
		if (!static::release()) {
			Billrun_Factory::log("Problem in releasing operation", Zend_Log::ALERT);
			return;
		}
		
	}
	
	/**
	 * Updating the payment status.
	 * 
	 * @param $response - the returned payment gateway status and stage of the payment.
	 * @param Payment payment- the current payment.
	 * @param String $gatewayName - name of the payment gateway.
	 * 
	 */
	public static function updateAccordingToStatus($response, $payment, $gatewayName) {
		if ($response['stage'] == "Completed") { // payment succeeded 
			$payment->updateConfirmation();
			$payment->setPaymentStatus($response, $gatewayName);
		} else if ($response['stage'] == "Pending") { // handle pending
			$payment->setPaymentStatus($response, $gatewayName);
		} else { //handle rejections
			if (!$payment->isRejected()) {
				Billrun_Factory::log('Rejecting transaction  ' . $payment->getId(), Zend_Log::INFO);
				$rejection = $payment->getRejectionPayment($response);
				$rejection->setConfirmationStatus(false);
				$rejection->save();
				$payment->markRejected();
				Billrun_Factory::dispatcher()->trigger('afterRejection', array($payment->getRawData()));
			} else {
				Billrun_Factory::log('Transaction ' . $payment->getId() . ' already rejected', Zend_Log::NOTICE);
			}
		}
	}
	
	public static function checkPendingStatus($pendingOptions){
		if (!empty($pendingOptions['aids'])) {
			self::$aids = Billrun_Util::verify_array($pendingOptions['aids'], 'int');
		}
		$pendingPayments = self::loadPending();
		foreach ($pendingPayments as $payment) {
			$gatewayName = $payment->getPaymentGatewayName();
			$paymentGateway = Billrun_PaymentGateway::getInstance($gatewayName);
			if (is_null($paymentGateway) || !$paymentGateway->hasPendingStatus()) {
				continue;
			}
			$txId = $payment->getPaymentGatewayTransactionId();
			Billrun_Factory::log("Checking status of pending payments", Zend_Log::INFO);
			$status = $paymentGateway->verifyPending($txId);
			if ($paymentGateway->isPending($status)) { // Payment is still pending
				Billrun_Factory::log("Payment with transaction id=" . $txId . ' is still pending', Zend_Log::INFO);
				$payment->updateLastPendingCheck();
				continue;
			}
			$response = $paymentGateway->checkPaymentStatus($status, $paymentGateway);
			Billrun_Factory::log("Updating payment with transaction id=" . $txId . ' to status ' . $response['stage'], Zend_Log::INFO);
			self::updateAccordingToStatus($response, $payment, $gatewayName);
		}
	}
	
	public function updateDetailsForPaymentGateway($gatewayName, $txId){
		if (is_null($txId)) {
			$this->data['payment_gateway'] = array('name' => $gatewayName);
		} else {
			$this->data['payment_gateway'] = array('name' => $gatewayName, 'transactionId' => $txId);
		}
		$this->save();
	}
	
	public function getPaymentGatewayDetails(){
		return $this->data['gateway_details'];
	}
	
	public function getAid(){
		return $this->data['aid'];
	}
	
	protected function getPaymentGatewayTransactionId(){
		return $this->data['payment_gateway']['transactionId'];
	}
			
	protected function getPaymentGatewayName(){
		return $this->data['payment_gateway']['name'];
	}
	
	public function setGatewayChargeFailure($message){
		return $this->data['failure_message'] = $message;
	}
	
	protected function getConflictingQuery() {
		if (!empty(self::$aids)){
			return array(
				'$or' => array(
					array('filtration' => 'all'),
					array('filtration' => array('$in' => self::$aids)),
				),
			);
		}
		
		return array();
	}
	
	protected function getInsertData() {
		return array(
			'action' => 'charge_account',
			'filtration' => (empty(self::$aids) ? 'all' : self::$aids),
		);
	}
	
	protected function getReleaseQuery() {
		return array(
			'action' => 'charge_account',
			'filtration' => (empty(self::$aids) ? 'all' : self::$aids),
			'end_time' => array('$exists' => false)
		);
	}
	
	public function getInvoicesIdFromReceipt() {
		$inv = $this->data['pays']['inv'];
		return array_keys($inv);
	}
	
	public function markApproved($status) {
		foreach ($this->getPaidBills() as $billType => $bills) {
			foreach (array_keys($bills) as $billId) {
				$billObj = Billrun_Bill::getInstanceByTypeAndid($billType, $billId);
				$billObj->updatePendingBillToConfirmed($this->getId(), $status)->save();
			}
		}
	}

	public function setPending($pending = true) {
		$this->data['pending'] = $pending;
	}
	
	public function getRejectionPayments($aid) {
		$query = array(
			'aid' => $aid,
			'$or' => array(
				array('rejected' => array('$eq' => true)),
				array('rejection' => array('$eq' => true)),
			),
		);
		return static::getBills($query);
	}
	
	public function getCancellationPayments($aid) {
		$query = array(
			'aid' => $aid,
			'$or' => array(
				array('cancelled' => array('$eq' => true)),
				array('cancel' => array('$exists' => true)),
			),
		);
		return static::getBills($query);
	}
	
	protected function buildFilterQuery($chargeFilters) {
		$filtersQuery = array();
		$errorMessage = self::validateChargeFilters($chargeFilters);
		if ($errorMessage) {
			throw new Exception($errorMessage);
		}
		if (!empty($chargeFilters['aids'])) {
			$aids = Billrun_Util::verify_array($chargeFilters['aids'], 'int');
			$aidsQuery = array('aid' => array('$in' => $aids));
			$filtersQuery = array_merge($filtersQuery, $aidsQuery);
		}
		
		if (!empty($chargeFilters['invoices'])) {
			$invoices = Billrun_Util::verify_array($chargeFilters['invoices'], 'int');
			$invoicesQuery = array('invoice_id' => array('$in' => $invoices));
			$filtersQuery = array_merge($filtersQuery, $invoicesQuery);
		}
		
		if (isset($chargeFilters['exclude_accounts'])) {
			$excludeAids = Billrun_Util::verify_array($chargeFilters['exclude_accounts'], 'int');
			$excludeAidsQuery = array('aid' => array('$nin' => $excludeAids));
			$filtersQuery = array_merge($filtersQuery, $excludeAidsQuery);
		}

		if (isset($chargeFilters['billrun_key'])) {
			$stampQuery = array('billrun_key' => $chargeFilters['billrun_key']);
			$filtersQuery = array_merge($filtersQuery, $stampQuery);
		}

		if (isset($chargeFilters['min_invoice_date'])) {
			$minInvoiceDateQuery = array('invoice_date' => array('$gte' => new MongoDate(strtotime($chargeFilters['min_invoice_date']))));
			$filtersQuery = array_merge($filtersQuery, $minInvoiceDateQuery);
		}

		return $filtersQuery;
	}

	protected function isRefundMode($options) {
		return isset($options['mode']) && $options['mode'] == 'refund';
	}

	protected function isChargeMode($options) {
		return isset($options['mode']) && $options['mode'] == 'charge';
	}
	
	protected function validateChargeFilters($filters) {
		$errorMessage = false;
		if (isset($filters['aids']) && isset($filters['exclude_accounts'])) {
			$errorMessage = "Wrong input! please choose between aids filter to exclude_accounts filter";
		}
		if (isset($filters['min_invoice_date']) && strtotime($filters['min_invoice_date']) === false) {
			$errorMessage = "Wrong input! min_invoice_date filter is invalid";
		}
		if (isset($filters['pay_mode']) && !in_array($filters['pay_mode'], array('total_debt','per_bill'))) {
			$errorMessage = "Wrong input! pay_mode can be total_debt or per_bill";
		}
		if (isset($filters['mode']) && !in_array($filters['mode'], array('charge','refund'))) {
			$errorMessage = "Wrong input! mode can be charge or refund";
		}
		if (isset($filters['pay_mode']) && ($filters['pay_mode'] == 'total_debt') && isset($filters['invoices'])) {
			$errorMessage = "Wrong input! when paying by invoices, pay mode must be per_bill";
		}
		if (!$errorMessage) {
			return self::validateArrayNumericValues($filters);
		}

		return $errorMessage;
	}
	
	protected function validateArrayNumericValues($filters) {
		$filtersPossibleArray = array();
		if (isset($filters['aids'])) {
			$filtersPossibleArray['aids'] = $filters['aids'];
		}
		if (isset($filters['exclude_accounts'])) {
			$filtersPossibleArray['exclude_accounts'] = $filters['exclude_accounts'];
		}
		if (isset($filters['invoices'])) {
			$filtersPossibleArray['invoices'] = $filters['invoices'];
		}
		foreach ($filtersPossibleArray as $filterName => $inputArray) {
			if (!is_array($inputArray)) {
				$inputArray = array($inputArray);
			}
			foreach ($inputArray as $value) {
				if (!Billrun_Util::IsIntegerValue($value)) {
					return 'Wrong input! non numeric values in ' . $filterName . ' filter';
				}
			}
		}
		
		return false;
	}

}
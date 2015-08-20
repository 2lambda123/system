<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing abstract subscriber class
 *
 * @package  Billing
 * @since    0.5
 */
class Billrun_Balance extends Mongodloid_Entity {

	/**
	 * Type of object
	 *
	 * @var string
	 */
	static protected $type = 'balance';

	/**
	 * Data container for subscriber details
	 * 
	 * @var array
	 * 
	 * @deprecated since version 4.0 use $_values of Mongodloid_Entity
	 */
	protected $data = array();
	
	protected $collection = null;

	public function __construct($options = array()) {
		// TODO: refactoring the read preference to the factory to take it from config
		$this->collection = self::getCollection();

		if (!isset($options['sid']) || !isset($options['aid'])) {
			Billrun_Factory::log('Error creating balance, no aid or sid' , Zend_Log::ALERT);
			return false;
		}
		
		if (!isset($options['charging_type'])) {
			$options['charging_type'] = 'postpaid';
		}
		$ret = $this->load($options['sid'], $options['urt'], $options['charging_type'], $options['usaget'])->getRawData();
		
		if (empty($ret) || count($ret) == 0) {
			if ($options['charging_type'] == 'postpaid') {
				$urtDate = date('Y-m-d h:i:s', $options['urt']->sec);
				$from = Billrun_Billrun::getBillrunStartTimeByDate($urtDate);
				$to = Billrun_Billrun::getBillrunEndTimeByDate($urtDate);
				$plan = Billrun_Factory::plan(array('name' => $options['plan'], 'time' => $options['urt']->sec, 'disableCache' => true));
				$plan_ref = $plan->createRef();
				$ret = $this->createBasicBalance($options['aid'], $options['sid'], $from, $to, $plan_ref);
			} else {
				$ret = array();
			}
		}
		
		parent::__construct($ret, self::getCollection());
	}
	
	public static function getCollection() {
		return Billrun_Factory::db()->balancesCollection()->setReadPreference('RP_PRIMARY');
	}

	/**
	 * method to set values in the loaded balance.
	 */
	public function __set($name, $value) {
		//if (array_key_exists($name, $this->data)) {
		$this->data[$name] = $value;
		//}
		return $this->data[$name];
	}

	/**
	 * method to get public field from the data container
	 * 
	 * @param string $name name of the field
	 * @return mixed if data field  accessible return data field, else null
	 */
	public function __get($name) {
		//if (array_key_exists($name, $this->data)) {
		return $this->data->get($name);
		//}
	}

	/**
	 * Pass function calls to the mongo entity that is used to hold  our data.
	 * @param type $name the name of the called funtion
	 * @param type $arguments the function arguments
	 * @return mixed what ever the  mongo entity returns
	 */
	public function __call($name, $arguments) {
		return call_user_func_array(array($this->data, $name), $arguments);
	}

	/**
	 * Loads the balance for subscriber
	 * @param type $subscriberId
	 * @param type $urt
	 * @param type $chargingType prepaid/postpaid
	 * @return subscriber's balance
	 */
	public function load($subscriberId, $urt, $chargingType = 'postpaid', $usageType = "") {
		Billrun_Factory::log()->log("Trying to load balance for subscriber " . $subscriberId . ". urt: " . $urt->sec . ". charging_type: " . $chargingType, Zend_Log::DEBUG);
		
		$query = array(
			'sid' => $subscriberId,
			'from' => array('$lte' => $urt),
			'to' => array('$gte' => $urt),
		);
		
		if ($chargingType === 'prepaid') {
			$query['$or'] = array (
				array("balance.totals.$usageType.usagev" => array('$lt' => 0)),
				array("balance.totals.$usageType.cost" => array('$lt' => 0)),
				array("balance.cost" => array('$lt' => 0)),
			);
		}

		$cursor = $this->collection->query($query)->cursor();
		if ($chargingType === 'prepaid') { // for pre-paid subscribers - choose correct balance by priority field
			$cursor = $cursor->sort(array('priority' => -1));
		}
		return $cursor->setReadPreference('RP_PRIMARY')
			->limit(1)->current();
	}

	/**
	 * method to check if the loaded balance is valid
	 */
	public function isValid() {
		return count($this->getRawData()) > 0;
	}

	/**
	 * Create a new subscriber in a given month and load it (if none exists).
	 * @param type $billrun_month
	 * @param type $sid
	 * @param type $plan
	 * @param type $aid
	 * @return boolean
	 * @deprecated since version 4.0
	 */
	public function create($billrunKey, $subscriber, $plan_ref) {
		$ret = $this->createBasicBalance($subscriber->aid, $subscriber->sid, $billrunKey, $plan_ref);
		$this->load($subscriber->sid, $billrunKey);
		return $ret;
	}

	/**
	 * Create a new balance  for a subscriber  in a given billrun
	 * @param type $account_id the account ID  of the subscriber.
	 * @param type $subscriber_id the subscriber ID.
	 * @param type $from billrun start date
	 * @param type $to billrun end date
	 * @param type $plan_ref the subscriber plan.
	 * @return boolean true  if the creation was sucessful false otherwise.
	 */
	protected function createBasicBalance($aid, $sid, $from, $to, $plan_ref) {
		$query = array(
			'sid' => $sid,
		);
		$update = array(
			'$setOnInsert' => self::getEmptySubscriberEntry($from , $to, $aid, $sid, $plan_ref),
		);
		$options = array(
			'upsert' => true,
			'new' => true,
			'w' => 1,
		);
		Billrun_Factory::log()->log("Create empty balance, from: " . date("Y-m-d", $from) . " to: " . date("Y-m-d", $to) . ", if not exists for subscriber " . $sid, Zend_Log::DEBUG);
		$output = $this->collection->findAndModify($query, $update, array(), $options, false);
		
		if (!is_array($output)) {
			Billrun_Factory::log('Error creating balance  , from: ' . date("Y-m-d", $from) . " to: " . date("Y-m-d", $to) . ', for subscriber ' . $sid . '. Output was: ' . print_r($output->getRawData(), true), Zend_Log::ALERT);
			return false;
		}
		Billrun_Factory::log('Added balance , from: ' . date("Y-m-d", $from) . " to: " . date("Y-m-d", $to) . ', to subscriber ' . $sid, Zend_Log::INFO);
		return $output;
	}

	/**
	 * get a new subscriber array to be place in the DB.
	 * @param type $billrun_month
	 * @param type $aid
	 * @param type $sid
	 * @param type $current_plan
	 * @return type
	 */
	public static function getEmptySubscriberEntry($from, $to, $aid, $sid, $plan_ref) {
		return array(
			//'billrun_month' => $billrun_month,
			'from' => new MongoDate($from),
			'to' => new MongoDate($to),
			'aid' => $aid,
			'sid' => $sid,
			'current_plan' => $plan_ref,
//			'balance' => self::getEmptyBalance("out_plan_"),
			'balance' => self::getEmptyBalance(),
			'tx' => new stdclass,
		);
	}

	/**
	 * Check  if  a given  balnace exists.
	 * @param type $subscriberId (optional)
	 * @param type $billrunKey (optional)
	 * @return boolean
	 */
	protected function isExists($subscriberId, $billrunKey) {

		$balance = $this->collection->query(array(
				'sid' => $subscriberId,
				'billrun_month' => $billrunKey
			))->cursor()->setReadPreference('RP_PRIMARY')
			->current();

		if (!count($balance->getRawData())) {
			return FALSE;
		}
		return TRUE;
	}

	/**
	 * Get an empty balance structure
	 * 
	 * @return array containing an empty balance structure.
	 */
	static public function getEmptyBalance() {
		$ret = array(
//			'totals' => array(),
			'cost' => 0,
		);
//		$usage_types = array('call', 'sms', 'data', 'incoming_call', 'incoming_sms', 'mms');
//		if (!is_null($prefix)) {
//			foreach ($usage_types as $usage_type) {
//				$usage_types[] = $prefix . $usage_type;
//			}
//		}
//		foreach ($usage_types as $usage_type) {
//			$ret['totals'][$usage_type] = self::getEmptyUsageTypeTotals();
//		}
		return $ret;
	}

	/**
	 * Get an empty plan usage counters.
	 * @return array containing an empty plan structure.
	 */
	static public function getEmptyUsageTypeTotals() {
		return array(
			'usagev' => 0,
			'cost' => 0,
			'count' => 0,
		);
	}

	//=============== ArrayAccess Implementation =============
	public function offsetExists($offset) {
		return isset($this->data[$offset]);
	}

	public function offsetGet($offset) {
		return $this->__get($offset);
	}

	public function offsetSet($offset, $value) {
		return $this->__set($offset, $value, true);
	}

	public function offsetUnset($offset) {
		unset($this->data[$offset]);
	}

}

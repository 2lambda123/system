<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * This class represents the billing cycle.
 *
 * @package  DataTypes
 * @since    5.2
 * @todo Create unit tests for this module
 */
class Billrun_Billingcycle {
	
	const PRECISION = 0.005;
	
	protected static $billingCycleCol = null;
    
	/**
	 * Table holding the values of the charging end dates.
	 * @var Billrun_DataTypes_CachedChargingTimeTable
	 */
	protected static $cycleEndTable = null;
	
	/**
	 * Table holding the values of the charging start dates.
	 * @var Billrun_DataTypes_CachedChargingTimeTable
	 */
	protected static $cycleStartTable = null;
        
	/**
	 * Cycle statuses cache (by page size)
	 * @var array
	 */
	protected static $cycleStatuses = array();

	/**
	 * returns the end timestamp of the input billing period
	 * @param type $key
	 * @return type int
	 */
	public static function getEndTime($key) {
		// Create the table if not already initialized
		if(!self::$cycleEndTable) {
			self::$cycleEndTable = new Billrun_DataTypes_CachedChargingTimeTable();
		}
		
		return self::$cycleEndTable->get($key);
	}

	/**
	 * returns the start timestamp of the input billing period
	 * @param type $key
	 * @return type int
	 */
	public static function getStartTime($key) {
		// Create the table if not already initialized
		if(!self::$cycleStartTable) {
			self::$cycleStartTable = new Billrun_DataTypes_CachedChargingTimeTable('-1 month');
		}

		return self::$cycleStartTable->get($key);
	}
	
	/**
	 * Return the date constructed from the current billrun key
	 * @return string
	 */
	protected static function getDatetime() {
		$dayofmonth = Billrun_Factory::config()->getConfigValue('billrun.charging_day', 1);
		return self::$billrunKey . str_pad($dayofmonth, 2, '0', STR_PAD_LEFT) . "000000";
	}
	
	/**
	 * method to receive billrun key by date
	 * 
	 * @param int $timestamp a unix timestamp, if set to null, use current time
	 * @param int $dayofmonth the day of the month require to get; if omitted return config value
	 * @return string date string of format YYYYmm
	 */
	public static function getBillrunKeyByTimestamp($timestamp=null, $dayofmonth = null) {
		if($timestamp === null) {
			$timestamp = time();
		}
		
		if (!$dayofmonth) {
			$dayofmonth = Billrun_Factory::config()->getConfigValue('billrun.charging_day', 1);
		}
		$format = "Ym";
		if (date("d", $timestamp) < $dayofmonth) {
			$key = date($format, $timestamp);
		} else {
			$key = date($format, strtotime('+1 day', strtotime('last day of this month', $timestamp)));
		}
		return $key;
	}

	/**
	 * returns the end timestamp of the input billing period
	 * @param date $date
	 */
	public static function getBillrunEndTimeByDate($date) {
		$dateTimestamp = strtotime($date);
		$billrunKey = self::getBillrunKeyByTimestamp($dateTimestamp);
		return self::getEndTime($billrunKey);
	}

	/**
	 * returns the start timestamp of the input billing period
	 * @param date $date
	 */
	public static function getBillrunStartTimeByDate($date) {
		$dateTimestamp = strtotime($date);
		$billrunKey = self::getBillrunKeyByTimestamp($dateTimestamp);
		return self::getStartTime($billrunKey);
	}
	
	/**
	 * Get the next billrun key
	 * @param string $key - Current key
	 * @return string The following key
	 */
	public static function getFollowingBillrunKey($key) {
		$datetime = $key . "01000000";
		$month_later = strtotime('+1 month', strtotime($datetime));
		$ret = date("Ym", $month_later);
		return $ret;
	}

	/**
	 * Get the previous billrun key
	 * @param string $key - Current key
	 * @return string The previous key
	 */
	public static function getPreviousBillrunKey($key) {
		$datetime = $key . "01000000";
		$month_before = strtotime('-1 month', strtotime($datetime));
		$ret = date("Ym", $month_before);
		return $ret;
	}
	
	/**
	 * method to get the last closed billing cycle
	 * if no cycle exists will return 197001 (equivalent to unix timestamp)
	 * 
	 * @return string format YYYYmm
	 */
	public static function getLastClosedBillingCycle() {
		$sort = array("billrun_key" => -1);
		$entry = Billrun_Factory::db()->billing_cycleCollection()->query(array())->cursor()->sort($sort)->limit(1)->current();
		if ($entry->isEmpty()) {
			return '197001';
		}
		return $entry['billrun_key'];
	}

	/**
	 * Preparing database for billing cycle rerun. 
	 * @param $billingCycleCol - billing cycle collection
	 * @param string $billrunKey - Billrun key
	 * 
	 */
    public static function removeBeforeRerun($billrunKey) {
		$billingCycleCol = self::getBillingCycleColl();
		$billrunColl = Billrun_Factory::db()->billrunCollection();
		$billrunQuery = array('billrun_key' => $billrunKey, 'billed' => array('$ne' => 1));
		$countersColl = Billrun_Factory::db()->countersCollection();
		$billrunsToRemove = $billrunColl->query($billrunQuery)->cursor();
		foreach ($billrunsToRemove as $billrun) {
			$invoicesToRemove[] = $billrun['invoice_id'];
			if (count($invoicesToRemove) > 1000) {  // remove bulks from billrun collection(bulks of 1000 records)
				$countersColl->remove(array('coll' => 'billrun', 'seq' => array('$in' => $invoicesToRemove)));
				$invoicesToRemove = array();
			}
		}
		if (count($invoicesToRemove) > 0) { // remove leftovers
			$countersColl->remove(array('coll' => 'billrun', 'seq' => array('$in' => $invoicesToRemove)));
		}
		$billingCycleCol->remove(array('billrun_key' => $billrunKey));
		Billrun_Aggregator_Customer::removeBeforeAggregate($billrunKey);
	}

	
	/**
	 * True if billing cycle had started. 
	 * @param $billingCycleCol - billing cycle collection
	 * @param string $billrunKey - Billrun key
	 * @param int $size - size of page 
	 * 
	 * @return bool - True if billing cycle had started.
	 */
	protected function hasCycleStarted($billrunKey, $size) {
		$billingCycleCol = self::getBillingCycleColl();
		$existsKeyQuery = array('billrun_key' => $billrunKey, 'page_size' => $size);
		$keyCount = $billingCycleCol->query($existsKeyQuery)->count();
		if ($keyCount < 1) {
			return false;
		}
		return true;
	}

	/**
	 * True if billing cycle is ended. 
	 * @param $billingCycleCol - billing cycle collection
	 * @param string $billrunKey - Billrun key
	 * @param int $size - size of page 
	 * 
	 * @return bool - True if billing cycle is ended.
	 */
	public static function hasCycleEnded($billrunKey, $size) {
		$billingCycleCol = self::getBillingCycleColl();
		$zeroPages = Billrun_Factory::config()->getConfigValue('customer.aggregator.zero_pages_limit');
		$numOfPages = $billingCycleCol->query(array('billrun_key' => $billrunKey, 'page_size' => $size))->count();
		$finishedPages = $billingCycleCol->query(array('billrun_key' => $billrunKey, 'page_size' => $size, 'end_time' => array('$exists' => 1)))->count();
		if (static::isBillingCycleOver($billingCycleCol, $billrunKey, $size, $zeroPages) && $numOfPages != 0 && $finishedPages == $numOfPages) {
			return true;
		}
		return false;
	}

	/**
	 * True if billing cycle is running for a given billrun key. 
	 * @param $billingCycleCol - billing cycle collection
	 * @param string $billrunKey - Billrun key
	 * @param int $size - size of page 
	 * 
	 * @return bool - True if generated all the bills from billrun objects
	 */
	public static function isCycleRunning($billrunKey, $size) {
		if (!self::hasCycleStarted($billrunKey, $size)) {
			return false;
		}
		if (self::hasCycleEnded($billrunKey, $size)) {
			return false;
		}
		return true;
	}
	
	/**
	 * Returns billrun keys of confirmed cycles according to the billrun keys that are transferred,
	 * if isn't transferred returns all confirmed cycles in the db.
	 * @param array $billrunKeys - Billrun keys.
	 * 
	 * @return bool - returns the keys of confirmed cycles
	 * 
	 */
	public static function getConfirmedCycles($billrunKeys = array()) {
		$billrunColl = Billrun_Factory::db()->billrunCollection();	
		if (!empty($billrunKeys)) {
			$pipelines[] = array(
				'$match' => array(
					'billrun_key' => array('$in' => $billrunKeys),
				),
			);
		}
		
		$pipelines[] = array(
			'$project' => array(
				'billrun_key' => 1,
				'confirmed' => array('$cond' => array('if' => array('$eq' => array('$billed', 1)), 'then' => 1 , 'else' => 0)),
			),
		);
		
		$pipelines[] = array(
			'$group' => array(
				'_id' => '$billrun_key',
				'confirmed' => array(
					'$sum' => '$confirmed',
				),
				'total' => array(
					'$sum' => 1,
				),
			),
		);
		
		$pipelines[] = array(
			'$project' => array(
				'billrun_key' => '$_id',
				'confirmed' => 1,
				'total' => 1,
			),
		);

		$potentialConfirmed = array();
		$results = $billrunColl->aggregate($pipelines);
		$resetCycles = self::getResetCycles($billrunKeys);
		foreach ($results as $billrunDetails) {
			if ($billrunDetails['confirmed'] == $billrunDetails['total']) {
				$potentialConfirmed[] = $billrunDetails['billrun_key'];
			}
		}
		$flipped = array_flip($potentialConfirmed);
		foreach ($resetCycles as $billrunKey) {
			unset($flipped[$billrunKey]);
		}
		$confirmedCycles = array_flip($flipped);
		return $confirmedCycles;	
	}

	/**
	 * Returns the percentage of cycle progress. 
	 * @param $billingCycleCol - billing cycle collection
	 * @param string $billrunKey - Billrun key
	 * @param int $size - size of page 
	 * 
	 *  @return cycle completion percentage 
	 */
	public static function getCycleCompletionPercentage($billrunKey, $size) {
		$billingCycleCol = self::getBillingCycleColl();
		$totalPagesQuery = array(
			'billrun_key' => $billrunKey
		);
		$totalPages = $billingCycleCol->query($totalPagesQuery)->count();
		$finishedPagesQuery = array(
			'billrun_key' => $billrunKey,
			'end_time' => array('$exists' => true)
		);
		$finishedPages = $billingCycleCol->query($finishedPagesQuery)->count();
		if (self::hasCycleEnded($billrunKey, $size)) {
			$completionPercentage = round(($finishedPages / $totalPages) * 100, 2);
		} else {
			$completionPercentage = round(($finishedPages / ($totalPages + 1)) * 100, 2);
		}

		return $completionPercentage;
	}
	
	/**
	 * Returns the number of generated bills.
	 * @param string $billrunKey - Billrun key
	 *
	 * @return int - number of generated bills.
	 */
	public static function getNumberOfGeneratedBills($billrunKey) {
		$billrunColl = Billrun_Factory::db()->billrunCollection();
		$query = array(
			'billrun_key' => $billrunKey,
			'billed' => 1
		);
		$generatedBills = $billrunColl->query($query)->count();
		return $generatedBills;
	}
	
	/**
	 * Returns the number of generated Invoices.
	 * @param string $billrunKey - Billrun key
	 * 
	 * @return int - number of generated Invoices.
	 */
	public static function getNumberOfGeneratedInvoices($billrunKey) {
		$billrunColl = Billrun_Factory::db()->billrunCollection();
		$query = array(
			'billrun_key' => $billrunKey
		);
		$generatedInvoices = $billrunColl->query($query)->count();
		return $generatedInvoices;
	}
		
	/**
	 * Computes the percentage of generated bills from billrun object.
	 * @param string $billrunKey - Billrun key
	 * @return percentage of completed bills
	 */
	public static function getCycleConfirmationPercentage($billrunKey) {
		$generatedInvoices = self::getNumberOfGeneratedInvoices($billrunKey);
		if ($generatedInvoices != 0) {
			return round((self::getNumberOfGeneratedBills($billrunKey) / $generatedInvoices) * 100, 2);
		}
		return 0;
	}
	
	public static function getCycleStatus($billrunKey, $size = null) {
		if (is_null($size)) {
			$size = (int) Billrun_Factory::config()->getConfigValue('customer.aggregator.size', 100);
		}
		if (isset(self::$cycleStatuses[$billrunKey][$size])) {
			return self::$cycleStatuses[$billrunKey][$size];
		}
		$currentBillrunKey = self::getBillrunKeyByTimestamp();
		$cycleConfirmed = !empty(self::getConfirmedCycles(array($billrunKey)));
		$cycleEnded = self::hasCycleEnded($billrunKey, $size);
		$cycleRunning = self::isCycleRunning($billrunKey, $size);
		
		$cycleStatus = '';
		if ($billrunKey == $currentBillrunKey) {
			$cycleStatus = 'current';
		}
		else if ($billrunKey > $currentBillrunKey) {
			$cycleStatus = 'future';
		}
		else if ($billrunKey < $currentBillrunKey && !$cycleEnded && !$cycleRunning) {
			$cycleStatus = 'to_run';
		} 
		
		else if ($cycleRunning) {
			$cycleStatus = 'running';
		}
		
		else if (!$cycleConfirmed && $cycleEnded) {
			$cycleStatus = 'finished';
		}
		
		else if ($cycleEnded && $cycleConfirmed) {
			$cycleStatus = 'confirmed';
		}
		self::$cycleStatuses[$billrunKey][$size] = $cycleStatus;
		return $cycleStatus;
	}
	
	public static function getBillingCycleColl() {
		if (is_null(self::$billingCycleCol)) {
			self::$billingCycleCol = Billrun_Factory::db()->billing_cycleCollection();
		}
		
		return self::$billingCycleCol;
	}
	
	/**
	 * Gets the newest confirmed billrun key
	 * 
	 * @return billrun key or 197001  if a confirmed cycle was not found
	 */
	public static function getLastConfirmedBillingCycle() {
		$maxIterations = 12;
		$billrunKey = self::getLastClosedBillingCycle();
		for ($i = 0; $i < $maxIterations; $i++) { // To avoid infinite loop
			if (!empty(self::getConfirmedCycles(array($billrunKey)))) {
				return $billrunKey;
			}
			$date = strtotime(($i + 1) . ' months ago');
			$billrunKey = self::getBillrunKeyByTimestamp($date);
		}
		return '197001';
	}
	
	/**
	 * Gets the oldest available billrun key (tenant creation or key from time received)
	 * 
	 * @param $startTime - string time to create billrun key from
	 * @return billrun key
	 */
	public static function getOldestBillrunKey($startTime) {
		$lastBillrunKey = Billrun_Billingcycle::getBillrunKeyByTimestamp($startTime);
		$registrationDate = Billrun_Factory::config()->getConfigValue('registration_date');
		if (!$registrationDate) {
			return $lastBillrunKey;
		}
		$registrationBillrunKey = Billrun_Billingcycle::getBillrunKeyByTimestamp($registrationDate->sec);
		return max(array($registrationBillrunKey, $lastBillrunKey));
	}

	public static function getLastNonRerunnableCycle() {
		$query = array('billed' => 1);
		$sort = array("billrun_key" => -1);
		$entry = Billrun_Factory::db()->billrunCollection()->query($query)->cursor()->sort($sort)->limit(1)->current();
		if ($entry->isEmpty()) {
			return FALSE;
		}
		return $entry['billrun_key'];
	}

	
	public static function isBillingCycleOver($cycleCol, $stamp, $size, $zeroPages=1){
		if (empty($zeroPages) || !Billrun_Util::IsIntegerValue($zeroPages)) {
			$zeroPages = 1;
		}
		$cycleQuery = array('billrun_key' => $stamp, 'page_size' => $size, 'count' => 0);
		$cycleCount = $cycleCol->query($cycleQuery)->count();
		
		if ($cycleCount >= $zeroPages) {
			Billrun_Factory::log("Finished going over all the pages", Zend_Log::DEBUG);
			return true;
		}		
		return false;
	}

	/**
	 * Returns accounts ids who have a confirmed invoice for the given cycle
	 * @param string $billrunKey
	 * @return array
	 */
	public static function getConfirmedAccountIds($billrunKey) {
		$billrunColl = Billrun_Factory::db()->billrunCollection();
		$query = array(
			'billrun_key' => $billrunKey,
			'billed' => 1,
		);
		$fields = array(
			'aid' => 1,
		);
		$confirmedInvoices = $billrunColl->find($query, $fields);
		$aids = array_column(iterator_to_array($confirmedInvoices),'aid');
		return $aids;
	}
	
	/**
	 * Returns reset cycles from the transferred billrun keys.
	 * @param string $billrunKeys - Billrun keys.
	 * 
	 * @return array - reset billrun keys.
	 * 
	 */
	public static function getResetCycles($billrunKeys) {
		$billrunCount = array();
		$cycleCount = array();
		$billrunColl = Billrun_Factory::db()->billrunCollection();
		$billingCycleCol = self::getBillingCycleColl();
		if (empty($billrunKeys)) {
			return array();
		}
		
		$pipelines[] = array(
			'$match' => array(
				'billrun_key' => array('$in' => $billrunKeys),
			),
		);

		$pipelines[] = array(
			'$group' => array(
				'_id' => '$billrun_key',
			),
		);
		
		$pipelines[] = array(
			'$project' => array(
				'billrun_key' => '$_id',
			),
		);

		$billrunResults = $billrunColl->aggregate($pipelines);
		$billingCycleResults = $billingCycleCol->aggregate($pipelines);
		foreach ($billrunResults as $billrunDetails){
			$billrunData = $billrunDetails->getRawData();
			$billrunCount[] = $billrunData['billrun_key'];
		}
		foreach ($billingCycleResults as $cycleDetails){
			$cycleData = $cycleDetails->getRawData();
			$cycleCount[] = $cycleData['billrun_key'];
		}
		$resetCycles = array_diff($billrunCount, $cycleCount);
		return $resetCycles;
	}

}
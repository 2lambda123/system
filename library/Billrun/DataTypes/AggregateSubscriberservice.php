<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * This class represents a service to be used by the subscriber aggregation logic.
 */
class Billrun_DataTypes_AggregateSubscriberservice {
	
	/**
	 * The service
	 * @var Billrun_DataTypes_Subscriberservice
	 */
	protected $service = null;
	
	/**
	 * Epoch value representing the activation of the service.
	 * @var int
	 */
	protected $activation = null;
	
	/**
	 * Epoch value representing the deactivation of the service.
	 * @var int
	 */
	protected $deactivation = null;
	
	/**
	 * Create a new instance of the Subscriberservice class
	 * @param array $options - Array of options containing name.
	 */
	public function __construct(array $options) {
		if(!isset($options['name'])) {
			return;
		}
		
		$this->name = $options['name'];
		
		if(isset($options['activation'])) {
			$this->activation = $options['activation'];
		} else {
			$this->activation = time();
		}
		
		if(isset($options['deactivation'])) {
			$this->deactivation = $options['deactivation'];
		}
		
		// Get the price from the DB
		$servicesColl = Billrun_Factory::db()->servicesCollection();
		$serviceQuery['name'] = $this->name;
		$service = $servicesColl->query($serviceQuery)->cursor()->current();
		if($service->isEmpty() || !isset($service['price'])) {
			// Signal invalid
			$this->name = null;
			return;
		}
		
		$this->price = $service['price'];
	}
	
	public function getName() {
		return $this->name;
	}
	
	/**
	 * Check if the service is valid.
	 * @return true if valid.
	 */
	public function isValid() {
		if(empty($this->name) || !is_string($this->name) || 
		  (!is_float($this->price)) && !Billrun_Util::IsIntegerValue($this->price)) {
			Billrun_Factory::log("Invalid parameters in subscriber service. name: " . print_r($this->name,1) . " price: " . print_r($this->price,1));
			return false;
		}
		
		return $this->checkDB();
	}
	
	/**
	 * Check if the service exists in the data base.
	 * @param integer $from - From timestamp
	 * @return boolean True if the service exists in the mongo
	 */
	protected function checkDB($from=null) {
		if(!$from) {
			$from = time();
		}
		
		// Check in the mongo.
		$servicesColl = Billrun_Factory::db()->servicesCollection();
		$serviceQuery['name'] = $this->name;
		$service = $servicesColl->query($serviceQuery)->cursor()->current();
		
		return !$service->isEmpty();
	}
	
	/**
	 * Get the subscriber service in array format
	 * @return array
	 */
	public function getService() {
		return array('name' => $this->name, "price" => $this->price);
	}
	
	/**
	 * 
	 * @param type $billrunKey
	 * @return int
	 * @todo This should be moved to a more fitting location
	 */
	protected function calcFractionOfMonth($billrunKey, $activation, $deactivation) {
		$start = Billrun_Billrun::getStartTime($billrunKey);
		
		// If the billing start date is after the deactivation return zero
		if($deactivation && ($start > $deactivation)) {
			return 0;
		}
		
		// If the billing start date is after the activation date, return a whole
		// fraction representing a full month.
		if(!$deactivation && ($start > $activation)) {
			return 1;
		}
		
		$end = Billrun_Billrun::getEndTime($billrunKey);
			
		// Validate the dates.
		if(!$this->validateCalcFractionOfMonth($billrunKey, $start, $end)) {
			return 0;
		}
		
		// Take the termination date.
		$termination = $end;
		if($deactivation && ($end > $deactivation)) {
			$termination = $deactivation;
		}
		
		// Take the starting
		$starting = $start;
		if($activation > $start) {
			$starting = $activation;
		}
		
		// Set the start date to the activation date.
		return $this->calcFraction($starting, $termination);
	}
	
	/**
	 * Validate the calc operation.
	 * @param type $billrunKey
	 * @param type $start
	 * @param type $end
	 * @return boolean
	 */
	protected function validateCalcFractionOfMonth($billrunKey, $start, $end, $activation, $deactivation) {
		// Validate dates.
		if($deactivation && ($deactivation < $activation)) {
			Billrun_Factory::log("Invalid dates in subscriber service");
			return false;
		}
		
		// Validate the dates.
		if ($end < $start) {
			return false;
		}
		
		// Normalize the activation.
		$activationDay = (int)date('d', $activation);
		$normalizedStamp = $billrunKey . (int)str_pad($activationDay, 2, '0', STR_PAD_LEFT) . "000000";
		$normalizedActivation = strtotime($normalizedStamp);
		
		if($end < $normalizedActivation) {
			Billrun_Factory::log("Service activation date is after billing end.");
			return false;
		}
		
		return true;
	}
	
	/**
	 * Calc the fraction between two dates out of a month.
	 * @param int $start - Start epoch
	 * @param int $end - End epoch
	 * @return float value.
	 */
	protected function calcFraction($start, $end) {
		$days_in_month = (int) date('t', $start);
		$start_day = date('j', $start);
		$end_day = date('j', $end);
		$start_month = date('F', $start);
		$end_month = date('F', $end);

		if ($start_month == $end_month) {
			$days_in_plan = (int) $end_day - (int) $start_day + 1;
		} else {
			$days_in_previous_month = $days_in_month - (int) $start_day + 1;
			$days_in_current_month = (int) $end_day;
			$days_in_plan = $days_in_previous_month + $days_in_current_month;
		}

		$fraction = $days_in_plan / $days_in_month;
		return $fraction;
	}
	
	/**
	 * Get the price of the service relative to the current billing cycle
	 * @param string $billrunKey - Current billrun key
	 * @return float - Price of the service relative to the current billing cycle.
	 */
	public function getPrice($billrunKey) {
		return $this->price * $this->calcFractionOfMonth($billrunKey);
	}
}

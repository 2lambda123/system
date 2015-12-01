<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * This is a prototype for a Realtime response action.
 *
 */
abstract class Billrun_ActionManagers_Realtime_Responder_Base {

	/**
	 * Db line to create the response from
	 * 
	 * @var type array
	 */
	protected $row;
	
	/**
	 * The name of the api response
	 * 
	 * @var string 
	 */
	protected $responseApiName = 'basic';

	/**
	 * Create an instance of the RealtimeAction type.
	 */
	public function __construct(array $options = array()) {
		$this->row = $options['row'];
		$this->responseApiName = $this->getResponsApiName();
	}
	
	/**
	 * Sets API name
	 */
	public abstract function getResponsApiName();

	/**
	 * Checks if the responder is valid
	 * 
	 * @return boolean
	 */
	public function isValid() {
		return (!is_null($this->row));
	}
	
	/**
	 * Checks if rebalance is needed
	 * 
	 * @return boolean
	 */
	public function isRebalanceRequired() {
		return false;
	}

	/**
	 * Get response message
	 */
	public function getResponse() {
		if ($this->isRebalanceRequired()) {
			$this->rebalance();
		}
		$responseData = $this->getResponseData();
		return $responseData;
	}
	
	/**
	 * Gets the fields to show on response
	 * 
	 * @return type
	 */
	protected function getResponseFields() {
		return Billrun_Factory::config()->getConfigValue('realtimeevent.responseData.basic', array());
	}

	/**
	 * Gets response message data
	 * 
	 * @return array
	 */
	protected function getResponseData() {
		$ret = array();
		$responseFields = $this->getResponseFields();
		foreach ($responseFields as $responseField => $rowField) {
			if (is_array($rowField)) {
				$ret[$responseField] = (isset($rowField['classMethod']) ? call_user_method($rowField['classMethod'], $this) : '');
			} else {
				$ret[$responseField] = (isset($this->row[$rowField]) ? $this->row[$rowField] : '');
			}
		}
		return $ret;
	}
	
	/**
	 * Gets the amount of usagev that was charged
	 * 
	 * @return type
	 */
	protected function getChargedUsagev() {
		$lines_coll = Billrun_Factory::db()->linesCollection();
		$query = $this->getRebalanceQuery();
		return $lines_coll->aggregate($query)->current()['sum'];
	}
	
	/**
	 * Calculate balance leftovers and add it to the current balance (if were taken due to prepaid mechanism)
	 */
	protected function rebalance() {
		$rebalanceUsagev = $this->getRealUsagev() - $this->getChargedUsagev();
		if ($rebalanceUsagev < 0) {
			$this->handleRebalanceRequired($rebalanceUsagev);
		}
	}

	/**
	 * In case balance is in over charge (due to prepaid mechanism), 
	 * adds a refund row to the balance.
	 * 
	 * @param type $rebalanceUsagev amount of balance (usagev) to return to the balance
	 */
	protected function handleRebalanceRequired($rebalanceUsagev) {
		$rebalanceRow = new Mongodloid_Entity($this->row);
		unset($rebalanceRow['_id']);
		$rebalanceRow['prepaid_rebalance'] = true;
		$rebalanceRow['usagev'] = $rebalanceUsagev;
		$customerPricingCalc = Billrun_Calculator::getInstance(array('type' => 'customerPricing', 'autoload' => false));
		$rate = $customerPricingCalc->getRowRate($rebalanceRow);
		$customerPricingCalc->updateSubscriberBalance($rebalanceRow, $rebalanceRow['usaget'], $rate);
	}

}

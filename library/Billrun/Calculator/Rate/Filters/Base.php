<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing basic base filter
 *
 * @package  calculator
 * @since 5.0
 */
class Billrun_Calculator_Rate_Filters_Base {

	public $params = array();

	/**
	 * wheather or not this can handle the rate query
	 * @var boolean
	 */
	protected $canHandle = true;

	public function __construct($params = array()) {
		$this->params = $params;
	}

	public function updateQuery(&$match, &$additional, &$group, &$additionalAfterGroup, &$sort, $row) {

		$this->updateMatchQuery($match, $row);
		$a = $this->updateAdditionalQuery($row);
		if ($a) {
			$additional[] = $a;
		}
		$this->updateGroupQuery($group, $row);
		$a2 = $this->updateAdditionaAfterGrouplQuery($row);
		if ($a2) {
			$additionalAfterGroup[] = $a2;
		}
		$this->updateSortQuery($sort, $row);
	}

	protected function getRowFieldValue($row, $field, $regex = '') {
		if ($field === 'computed') {
			return $this->getComputedValue($row);
		}
		if (isset($row['uf'][$field])) {
			return $this->regexValue($row['uf'][$field], $regex);
		}

		if (isset($row[$field])) {
			return $this->regexValue($row[$field], $regex);
		}
		Billrun_Factory::log("Cannot get row value for rate. field: " . $field . " stamp: " . $row['stamp'], Zend_Log::NOTICE);
		return '';
	}

	protected function regexValue($value, $regex) {
		if (empty($regex) || !Billrun_Util::isValidRegex($regex)) {
			return $value;
		}

		return preg_replace($regex, '', $value);
	}

	/**
	 * Gets a computed (regex/condition) field value
	 * 
	 * @param array $row
	 * @return value after regex applying, in case of condition - 1 if the condition is met, 0 otherwise
	 */
	protected function getComputedValue($row) {
		if (!isset($this->params['computed'])) {
			return '';
		}
		$spceialQueries = array(
			'$exists' => array('$exists' => 1),
			'$existsFalse' => array('$exists' => 0),
		);
		$computedType = Billrun_Util::getIn($this->params, array('computed', 'type'), 'regex');
		$firstValKey = Billrun_Util::getIn($this->params, array('computed', 'line_keys', 0, 'key'), '');
		$firstValRegex = Billrun_Util::getIn($this->params, array('computed', 'line_keys', 0, 'regex'), '');
		$firstVal = $this->getRowFieldValue($row, $firstValKey, $firstValRegex);
		if ($computedType === 'regex') {
			return $firstVal;
		}
		$operator = $this->params['computed']['operator'];
		$secondValKey = Billrun_Util::getIn($this->params, array('computed', 'line_keys', 1, 'key'), '');
		if ($operator === '$regex') { // in case of hard coded value
			$secondVal = $secondValKey;
		} else {
			$secondValRegex = Billrun_Util::getIn($this->params, array('computed', 'line_keys', 1, 'regex'), '');
			$secondVal = $this->getRowFieldValue($row, $secondValKey, $secondValRegex);
		}
		
		$data = array('first_val' => $firstVal);
		$query = array(
			'first_val' => array(
				$operator => $secondVal,
			),
		);
		if (!empty($spceialQueries[$operator]) ) {
			$data = $row;
			$op = $operator === '$existsFalse' ? '$and' : '$or';
			$query = array($op => [
					[$firstValKey => $spceialQueries[$operator]],
					['uf.'.$firstValKey => $spceialQueries[$operator]],
				]
			);
		}

		$res = Billrun_Utils_Arrayquery_Query::exists($data, $query);
		return $this->getComputedValueResult($row, $res);
	}

	/**
	 * returns a value for a computed line key (the value received by a calculation on the CDR fields)
	 * 
	 * @param array $row
	 * @param boolean $conditionRes
	 * @return value to compare against the rate key
	 */
	protected function getComputedValueResult($row, $conditionRes) {
		if (Billrun_Util::getIn($this->params, array('computed', 'must_met'), false) && !$conditionRes) {
			$this->canHandle = false;
			return false;
		}

		$projectionKey = $conditionRes ? 'on_true' : 'on_false';
		$key = Billrun_Util::getIn($this->params, array('computed', 'projection', $projectionKey, 'key'), '');
		switch ($key) {
			case ('condition_result'):
				return $conditionRes;
			case ('hard_coded'):
				return Billrun_Util::getIn($this->params, array('computed', 'projection', $projectionKey, 'value'), '');
			default:
				$regex = Billrun_Util::getIn($this->params, array('computed', 'projection', $projectionKey, 'regex'), '');
				return $this->getRowFieldValue($row, $key, $regex);
		}
	}

	protected function updateMatchQuery(&$match, $row) {
		
	}

	protected function updateAdditionalQuery($row) {
		
	}

	protected function updateGroupQuery(&$group, $row) {
		
	}

	protected function updateAdditionaAfterGrouplQuery($row) {
		
	}

	protected function updateSortQuery(&$sort, $row) {
		
	}

	/**
	 * Whether or not the current filter can handle the query building
	 * currently, will return false only if a must_met condition is set on the CDR fields and the values are not equal
	 * 
	 * @return boolean
	 */
	public function canHandle() {
		return $this->canHandle;
	}

}

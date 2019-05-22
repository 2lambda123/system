<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * This class represents the plan data to be aggregated.
 */
class Billrun_Cycle_Data_Plan extends Billrun_Cycle_Data_Line {

	use Billrun_Traits_ForeignFields;
	
	protected $plan = null;
	protected $name = null;
	protected $start = 0;
	protected $end = PHP_INT_MAX;
	protected $cycle;
	protected $foreignFields = array();

	public function __construct(array $options) {
		parent::__construct($options);
		if (!isset($options['plan'], $options['cycle'])) {
			Billrun_Factory::log("Invalid aggregate plan data!");
			return;
		}
		$this->name = $options['plan'];
		$this->plan = $options['plan'];
		$this->cycle = $options['cycle'];
		$this->start = Billrun_Util::getFieldVal($options['start'], $this->start);
		$this->end = Billrun_Util::getFieldVal($options['end'], $this->end);
		$this->foreignFields = $this->getForeignFields(array('plan' => $options), $this->stumpLine);
	}

	protected function getCharges($options) {
		$charger = new Billrun_Plans_Charge();
		return $charger->charge($options, $options['cycle']);
	}

	protected function getLine($chargeingKey, $chargeData) {

		$entry = $this->getFlatLine();
		$entry['aprice'] = $chargeData['value'];
		$entry['full_price'] = $chargeData['full_price'];
		$entry['charge_op'] = $chargeingKey;
		if (isset($chargeData['cycle'])) {
			$entry['cycle'] = $chargeData['cycle'];
		}
		$entry['stamp'] = $this->generateLineStamp($entry);
		if (!empty($chargeData['start']) && $this->cycle->start() < $chargeData['start']) {
			$entry['start'] = new MongoDate($chargeData['start']);
		}
		if (!empty($chargeData['end']) && $this->cycle->end() - 1 > $chargeData['end']) {
			$entry['end'] = new MongoDate($chargeData['end']);
		}

		$entry = $this->addTaxationToLine($entry);
		$entry = $this->addExternalFoerignFields($entry);
		$entry = Billrun_Utils_Plays::addPlayToLineDuringCycle($entry);
		
		if (!empty($this->plan)) {
			$entry['plan'] = $this->plan;
		}
		return $entry;
	}

	protected function getFlatLine() {
		$flatEntry = array(
			'plan' => $this->plan,
			'name' => $this->name,
			'process_time' => new MongoDate(),
			'usagev' => 1
		);

		if (FALSE !== $this->vatable) {
			$flatEntry['vatable'] = TRUE;
		}

		
		return array_merge($flatEntry, $this->stumpLine);
	}
	
	protected function addExternalFoerignFields($entry) {
		return array_merge($this->getForeignFields(array(), array_merge($this->foreignFields,$entry),TRUE),$entry);
	}

	protected function generateLineStamp($line) {
		return md5($line['charge_op'] . '_' . $line['aid'] . '_' . $line['sid'] . $this->plan . '_' . $this->cycle->start() . $this->cycle->key() . '_' . $line['aprice'].$this->start);
	}
	
	//TODO move this to the account/subscriber lines addition logic and work in batch mode.
	protected function addTaxationToLine($entry) {
		$entryWithTax = FALSE;
		for ($i = 0; $i < 3 && !$entryWithTax; $i++) {//Try 3 times to tax the line.
			$taxCalc = Billrun_Calculator::getInstance(array('autoload' => false, 'type' => 'tax'));
			$entryWithTax = $taxCalc->updateRow($entry);
			if (!$entryWithTax) {
				Billrun_Factory::log("Taxation of {$entry['name']} failed retring...", Zend_Log::WARN);
				sleep(1);
			}
		}
		if (!empty($entryWithTax)) {
			$entry = $entryWithTax;
		} else {
			throw new Exception("Couldn`t tax flat line {$entry['name']} for aid: {$entry['aid']} , sid : {$entry['sid']}");
		}

		return $entry;
	}

}

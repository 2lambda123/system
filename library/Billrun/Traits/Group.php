<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Trait used to abstract the Group of rates
 * Used in Plan and Service
 *
 * @package  Billing
 * @since    5.2
 */
trait Billrun_Traits_Group {

	/**
	 * container of the entity data
	 * 
	 * @var mixed
	 */
	protected $data = null;
	protected $groupSelected = null;
	protected $groups = null;

	public function getName() {
		return $this->get('name');
	}

	/**
	 * method to receive all group rates of the current plan
	 * @param array $rate the rate to check
	 * @param string $usageType usage type to check
	 * @return false when no group rates, else array list of the groups
	 * @since 2.6
	 */
	public function getRateGroups($rate, $usageType) {
		if (isset($rate['rates'][$usageType]['groups'])) {
			$groups = $rate['rates'][$usageType]['groups'];
		} else if (($name = $this->getName()) && isset($rate['rates'][$usageType]['groups'][$name])) {
			$groups = $rate['rates'][$usageType]['groups'][$name];
		} else if (isset($rate['rates'][$usageType]['groups']['BASE'])) {
			$groups = $rate['rates'][$usageType]['groups']['BASE'];
		} else {
			return array();
		}
		if (!empty($groups) && isset($this->data['include']['groups'])) {
			return array_intersect($groups, array_keys($this->data['include']['groups']));
		}
		return array();
	}

	public function setEntityGroup($group) {
		$this->groupSelected = $group;
	}

	public function getEntityGroup() {
		return $this->groupSelected;
	}

	public function unsetGroup($group) {
		$item = array_search($group, $this->groups);
		if (isset($this->groups[$item])) {
			unset($this->groups[$item]);
		}
	}

	/**
	 * method to check if rate is part of group of rates balance
	 * there is option to create balance for group of rates
	 * 
	 * @param array $rate the rate to check
	 * @param string $usageType the usage type to check
	 * @return true when the rate is part of group else false
	 */
	public function isRateInEntityGroup($rate, $usageType) {
		if (count($this->getRateGroups($rate, $usageType))) {
			return true;
		}
		return false;
	}

	/**
	 * method to receive the strongest group of list of groups 
	 * method will init the groups list if not loaded yet
	 * by default, the strongest rule is simple the first rule selected (in the plan)
	 * rules can be complex with plugins (see vodafone and ird plugins for example)
	 * 
	 * @param array $rate the rate to check
	 * @param string $usageType the usage type to check
	 * @param boolean $reset reset to the first group plan
	 * 
	 * @return false when no group found, else string name of the group selected
	 */
	protected function setNextStrongestGroup($rate, $usageType, $reset = FALSE) {
		if (is_null($this->groups)) {
			$this->groups = $this->getRateGroups($rate, $usageType);
		}
		if (!count($this->groups)) {
			$this->setEntityGroup(FALSE);
		} else if ($reset || is_null($this->getEntityGroup())) { // if reset required or it's the first set
			$this->setEntityGroup(reset($this->groups));
		} else if (next($this->groups) !== FALSE) {
			$this->setEntityGroup(current($this->groups));
		} else {
			$this->setEntityGroup(FALSE);
		}

		return $this->getEntityGroup();
	}

	/**
	 * method to receive the usage left in group of rates of current plan
	 * 
	 * @param array $subscriberBalance subscriber balance
	 * @param array $rate the rate to check the balance
	 * @param string $usageType the 
	 * @return mixed
	 */
	public function usageLeftInEntityGroup($subscriberBalance, $rate, $usageType) {
		do {
			$groupSelected = $this->setNextStrongestGroup($rate, $usageType);
			// group not found
			if ($groupSelected === FALSE) {
				$rateUsageIncluded = 0;
				// @todo: add more logic instead of fallback to first
				$this->setEntityGroup($this->setNextStrongestGroup($rate, $usageType, true));
				break; // do-while
			}
			// not group included in the specific usage try to take iterate next group
			if (!isset($this->data['include']['groups'][$groupSelected][$usageType])) {
				continue;
			}
			$rateUsageIncluded = $this->data['include']['groups'][$groupSelected][$usageType];
			if (isset($this->data['include']['groups'][$groupSelected]['limits'])) {
				// on some cases we have limits to unlimited
				$limits = $this->data['include']['groups'][$groupSelected]['limits'];
				Billrun_Factory::dispatcher()->trigger('planGroupRule', array(&$rateUsageIncluded, &$groupSelected, $limits, $this, $usageType, $rate, $subscriberBalance));
				if ($rateUsageIncluded === FALSE) {
					$this->unsetGroup($this->getEntityGroup());
				}
			}
		}
		// @todo: protect max 5 loops
		while ($groupSelected === FALSE);

		if ($rateUsageIncluded === 'UNLIMITED') {
			return PHP_INT_MAX;
		}

		if (isset($subscriberBalance['balance']['groups'][$groupSelected][$usageType]['usagev'])) {
			$subscriberSpent = $subscriberBalance['balance']['groups'][$groupSelected][$usageType]['usagev'];
		} else {
			$subscriberSpent = 0;
		}
		$usageLeft = $rateUsageIncluded - $subscriberSpent;
		return floatval($usageLeft < 0 ? 0 : $usageLeft);
	}

}

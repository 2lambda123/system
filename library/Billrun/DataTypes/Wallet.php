<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * This class represents the wallet inside the balance.
 *
 * @package  DataTypes
 * @since    4
 */
class Billrun_DataTypes_Wallet {

	/**
	 * This variable is for the field value name of this plan.
	 * @var string
	 */
	protected $valueFieldName = null;

	/**
	 * Value of the balance.
	 * @var Object
	 */
	protected $value = null;

	/**
	 * Name of the usaget of the plan [call\sms etc].
	 * @var string
	 */
	protected $chargingBy = null;

	/**
	 * Type of the charge of the plan. [usaget\cost etc...]
	 * @var string.
	 */
	protected $chargingByUsaget = null;

	/**
	 * The period for when is this wallet active.
	 * @var array
	 */
	protected $period = null;
	
	/**
	 * The name of the pp include.
	 * @var string
	 */
	protected $ppName = null;
	
	/**
	 * The ID of the pp include.
	 * @var integer
	 */
	protected $ppID = null;

	/**
	 * Create a new instance of the wallet type.
	 * @param array $chargingBy
	 * @param array $chargingByValue
	 * @param array $ppPair Pair of prepaid includes values.
	 */
	public function __construct($chargingBy, $chargingByValue, $ppPair) {
		$chargingByUsaget = $chargingBy;

		$this->ppID = $ppPair['pp_includes_external_id'];
		$this->ppName = $ppPair['pp_includes_name'];
		
		// The wallet does not handle the period.
		if (isset($chargingByValue['period'])) {
			$this->period = $chargingByValue['period'];
			unset($chargingByValue['period']);
		}

		if (!is_array($chargingByValue)) {
			$this->valueFieldName = 'balance.' . str_replace("total_", "totals.", $chargingBy);
			$this->value = $chargingByValue;
		} else {
			list($chargingByUsaget, $this->value) = each($chargingByValue);
			$this->valueFieldName = 'balance.totals.' . $chargingBy . '.' . $chargingByUsaget;
		}

		$this->chargingBy = $chargingBy;
		$this->chargingByUsaget = $chargingByUsaget;

		$this->setValue();
	}

	/**
	 * Sets the value. If unable to convert to integer, throws an exception.
	 * @throws InvalidArgumentException
	 */
	protected function setValue() {
		// Convert the value to an integer.
		$numValue = Billrun_Util::toNumber($this->value);
		if ($numValue === false) {
			throw new InvalidArgumentException("Wallet initialized with non integer value " . $this->value);
		}
		$this->value = $numValue;
	}

	/**
	 * Get the value for the current wallet.
	 * @return The current wallet value.
	 */
	public function getValue() {
		return $this->value;
	}

	/**
	 * Get the period for the current wallet, null if not exists.
	 * @return The current wallet period.
	 * @todo Create a period object.
	 */
	public function getPeriod() {
		return $this->period;
	}

	/**
	 * Retur the name of the field to set the value of the balance.
	 * @return The name of the field to set the value of the balance.
	 */
	public function getFieldName() {
		return $this->valueFieldName;
	}

	/**
	 * Get the charging by string value.
	 * @return string
	 */
	public function getChargingBy() {
		return $this->chargingBy;
	}

	/**
	 * Get the charging by usage t string value.
	 * @return string
	 */
	public function getChargingByUsaget() {
		return $this->chargingByUsaget;
	}
	
	/**
	 * Get the pp include name.
	 * @return string
	 */
	public function getPPName() {
		return $this->ppName;
	}
	/**
	 * Get the pp include ID.
	 * @return integer
	 */
	public function getPPID() {
		return $this->ppID;
	}

}

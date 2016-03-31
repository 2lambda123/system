<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Holds the logic for updating balances using the prepaid include.
 *
 * @author Tom Feigin
 */
class Billrun_ActionManagers_Balances_Updaters_PrepaidInclude extends Billrun_ActionManagers_Balances_Updaters_Updater {

	protected $type = 'PrepaidInclude';

	/**
	 * Get the array of strings to translate the names of the input fields to the names used in the db.
	 * @return array.
	 */
	protected function getTranslateFields() {
		// TODO: Should this be in conf?
		return array(
			'pp_includes_name' => 'name',
			'pp_includes_external_id' => 'external_id'
		);
	}

	/**
	 * Update the balances, based on the plans table
	 * @param type $query - Query to find row to update.
	 * @param type $recordToSet - Values to update.
	 * @param type $subscriberId - Id for the subscriber to update.
	 * @return The updated record, false if failed.
	 */
	public function update($query, $recordToSet, $subscriberId) {
		// If updating by prepaid include the user must specify an expiration date.
		if (!$recordToSet['to']) {
			$errorCode = Billrun_Factory::config()->getConfigValue("balances_error_base") + 6;
			$this->reportError($errorCode, Zend_Log::NOTICE);
			return false;
		}

		// No value is set.
		if (!isset($recordToSet['value'])) {
			$errorCode = Billrun_Factory::config()->getConfigValue("balances_error_base") + 7;
			$this->reportError($errorCode, Zend_Log::NOTICE);
			return false;
		}

		$db = Billrun_Factory::db();
		$prepaidIncludes = $db->prepaidincludesCollection();
		$prepaidRecord = $this->getRecord($query, $prepaidIncludes, $this->getTranslateFields());
		if (!$prepaidRecord) {
			$errorCode = Billrun_Factory::config()->getConfigValue("balances_error_base") + 8;
			$this->reportError($errorCode, Zend_Log::NOTICE);
			return false;
		}

		if (isset($prepaidRecord['external_id']) && 
			in_array($prepaidRecord['external_id'], Billrun_Factory::config()->getConfigValue('protected_unlimited_pp_includes', array()))) {
			$recordToSet['to'] = new MongoDate(strtotime(self::UNLIMITED_DATE));
		}
		// Get the subscriber.
		$subscriber = $this->getSubscriber($subscriberId);

		// Subscriber was not found.
		if ($subscriber===false) {
			return false;
		}

		// Set subscriber to query.
		$findQuery['aid'] = $subscriber['aid'];
		$findQuery['sid'] = $subscriber['sid'];

		// Create a default balance record.
		$defaultBalance = $this->getDefaultBalance($subscriber, $prepaidRecord, $recordToSet);

		$chargingPlan = $this->getPlanObject($prepaidRecord, $recordToSet);

		// Get the balance with the current value field.
		$findQuery[$chargingPlan->getFieldName()]['$exists'] = 1;
		$findQuery['pp_includes_external_id'] = $chargingPlan->getPPID();
		
		$updateResult = $this->updateBalance($chargingPlan, $findQuery, $defaultBalance, $recordToSet['to']);
		$normalizeResult = $this->normalizeBalance($findQuery, $subscriber['plan'], $chargingPlan);
		if($normalizeResult === false) {
			return false;
		}
		
		// Report on changes
		if($normalizeResult['nModified'] > 0) {
			$valueName = $chargingPlan->getFieldName();
			$beforeNormalizing = $updateResult[0]['balance'][$valueName];
			$updateResult[0]['balance'][$valueName] = $normalizeResult['max'];
			$updateResult[0]['normalized']['before'] = $beforeNormalizing - $chargingPlan->getValue();
			$updateResult[0]['normalized']['after'] = $beforeNormalizing;
			$updateResult[0]['normalized']['normalized'] = $normalizeResult['max'];
			
			if(isset($normalizeResult['bill_err'])) {
				// Report the error.
				$this->reportError($normalizeResult['bill_err'], Zend_Log::ERR);	
			}
		}
		
		$updateResult[0]['source'] = $prepaidIncludes->createRefByEntity($prepaidRecord);
		$updateResult[0]['subscriber'] = $subscriber;
		return $updateResult;
	}

	/**
	 * Get the plan object built from the record values.
	 * @param array $prepaidRecord - Prepaid record.
	 * @param array $recordToSet - Record with values to be set.
	 * @return \Billrun_DataTypes_Wallet Plan object built with values.
	 */
	protected function getPlanObject($prepaidRecord, $recordToSet) {
		$chargingBy = $prepaidRecord['charging_by'];
		$chargingByUsaget = $prepaidRecord['charging_by_usaget'];
		if ($chargingBy == $chargingByUsaget) {
			$chargingByValue = $recordToSet['value'];
		} else {
			$chargingByValue = array($chargingBy => $recordToSet['value']);
		}

		$ppPair['priority'] = $prepaidRecord['priority'];
		$ppPair['pp_includes_name'] = $prepaidRecord['name'];
		$ppPair['pp_includes_external_id'] = $prepaidRecord['external_id'];
		
		return new Billrun_DataTypes_Wallet($chargingByUsaget, $chargingByValue, $ppPair);
	}

	/**
	 * Get the query to be used to normalize the balance.
	 * @param Billrun_DataTypes_Wallet $wallet - The wallet used
	 * @param int $maxValue - The max value of the balance.
	 * @return array the array used to update the mongo.
	 */
	protected function getNormalizedBalanceQuery($wallet, $maxValue) {
		return array('$set' => array($wallet->getFieldName() => $wallet->getValue()));
	}
	
	/**
	 * Get the update balance query. 
	 * @param Mongoldoid_Collection $balancesColl
	 * @param array $query - Query for getting tha balance.
	 * @param Billrun_DataTypes_Wallet $chargingPlan
	 * @param array $defaultBalance - Default balance to set.
	 * @return array Query for set updating the balance.
	 */
	protected function getUpdateBalanceQuery($balancesColl, $query, $chargingPlan, $defaultBalance) {
		$update = array();
		$balance = $balancesColl->query($query)->cursor()->current();
		$this->balanceBefore[$query['pp_includes_external_id']] = $balance;
		
		// If the balance doesn't exist take the setOnInsert query, 
		// if it exists take the set query.
		if (!isset($balance) || $balance->isEmpty()) {
			$update = $this->getSetOnInsert($chargingPlan, $defaultBalance);
		} else {
			$this->handleZeroing($query, $balancesColl, $chargingPlan->getFieldName());

			$update = $this->getSetQuery($chargingPlan);
		}

		return $update;
	}

	/**
	 * Update a single balance.
	 * @param Billrun_DataTypes_Wallet $chargingPlan
	 * @param array $query
	 * @param array $defaultBalance
	 * @param MongoDate $toTime
	 * @return Array with the wallet as the key and the Updated record as the value.
	 */
	protected function updateBalance($chargingPlan, $query, $defaultBalance, $toTime) {
		$balancesColl = Billrun_Factory::db()->balancesCollection();

		$balanceQuery = array_merge($query, Billrun_Util::getDateBoundQuery()); 
		$update = $this->getUpdateBalanceQuery($balancesColl, $balanceQuery, $chargingPlan, $defaultBalance);	
		$this->setToForUpdate($update, $toTime);
		
		$options = array(
			'upsert' => true,
			'new' => true,
		);

		$balance = $balancesColl->findAndModify($balanceQuery, $update, array(), $options, true);

		// Return the new document.
		return array(
			array(
				'wallet' => $chargingPlan,
				'balance' => $balance
			)
		);
	}

	/**
	 * Get a default balance record, without charging by.
	 * @param type $subscriber
	 * @param type $prepaidRecord
	 * @param type $recordToSet
	 */
	protected function getDefaultBalance($subscriber, $prepaidRecord, $recordToSet) {
		$defaultBalance = array();
		$defaultBalance['from'] = new MongoDate();

		$defaultBalance['to'] = $recordToSet['to'];
		$defaultBalance['sid'] = $subscriber['sid'];
		$defaultBalance['aid'] = $subscriber['aid'];
//		$defaultBalance['current_plan'] = $this->getPlanRefForSubscriber($subscriber);
		$defaultBalance['charging_type'] = $subscriber['charging_type'];
		$defaultBalance['charging_by'] = $prepaidRecord['charging_by'];
		$defaultBalance['charging_by_usaget'] = $prepaidRecord['charging_by_usaget'];

		return $defaultBalance;
	}

}

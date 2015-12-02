<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Holds the logic for updating balances using the secret card number.
 *
 * @author tom
 */
class Billrun_ActionManagers_Balances_Updaters_Secret extends Billrun_ActionManagers_Balances_Updaters_ChargingPlan {
	
	/**
	 * Update the balances, based on the plans table
	 * @param type $query - Query to find row to update.
	 * @param type $recordToSet - Values to update.
	 * @param type $subscriberId - Id for the subscriber to update.
	 * @return The updated record, false if failed.
	 */
	public function update($query, $recordToSet, $subscriberId) {
		$cardsColl = Billrun_Factory::db()->cardsCollection();
		// Get the record.
		$dateQuery = array('to' => array('$gt', new MongoDate()));
		$finalQuery = array_merge($dateQuery, $query);
		$finalQuery['status'] = array('$eq' => 'Active');
		
		$cardRecord = $cardsColl->query($finalQuery)->cursor()->current();
		if(empty($cardRecord)) {
			$error = "Invalid card received, might be cancelled";
			$this->reportError($error, Zend_Log::NOTICE);
			return false;
		}
		
		if (!$this->validateServiceProviders($cardRecord, $recordToSet)) {
			return false;
		}
		
		$this->signalCardAsUsed($cardRecord, $subscriberId);
		
		// Build the plan query from the card plan field.
		$planQuery = array('charging_plan_name' => $cardRecord['charging_plan']);
		
		return parent::update($planQuery, $recordToSet, $subscriberId);
	}
	
	/**
	 * Signal a given card as used after it has been used to charge a balance.
	 * @param mongoEntity $cardRecord - Record to set as canceled in the mongo.
	 */
	protected function signalCardAsUsed($cardRecord, $subscriberId) {
		$cardsColl = Billrun_Factory::db()->cardsCollection();
		$query = array('_id' => array('$eq' => $cardRecord['_id']));
		$update = array('$set' => 
			array(
				'status' => 'Used',
				'sid'    => $subscriberId,
			),
		);
		
		$options = array(
			'upsert' => false,
			'w' => 1,
		);

		$cardsColl->findAndModify($query, $update, array(), $options, true);
	}
}

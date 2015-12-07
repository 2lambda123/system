<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * This is a parser to be used by the subscribers action.
 *
 * @author tom
 */
class Billrun_ActionManagers_Subscribers_Update extends Billrun_ActionManagers_Subscribers_Action{
	// TODO: Create a generic update action class. This class shares some logic with the cards and balances update action. The setUpdateRecord function is shared.
	
	/**
	 * Field to hold the data to be written in the DB.
	 * @var type Array
	 */
	protected $recordToSet = array();
	
	protected $query = array();
	protected $trackHistory = true;
	protected $keepLines = true;
	protected $keepBalances = true;
	
	/**
	 */
	public function __construct() {
		parent::__construct(array('error' => "Success updating subscriber"));
	}
	
	/**
	 * Close all the open balances for a subscriber.
	 * 
	 * @param string $sid - The sid of the user to close the balance for.
	 * @param string $aid - The aid of the user to close the balance for.
	 */
	protected function closeBalances($sid, $aid) {
		// Find all balances.
		$balancesUpdate = array('$set' => array('to', new MongoDate()));
		$balancesQuery = 
			array('sid' => $sid, 
				  'aid' => $aid);
		$options = array(
			'upsert' => false,
			'new' => false,
			'w' => 1,
		);
		// TODO: Use balances DB/API proxy class.
		$balancesColl = Billrun_Factory::db()->balancesCollection();
		$balancesColl->findAndModify($balancesQuery, $balancesUpdate, array(), $options, true);
	}
	
	/**
	 * Keeps history before the records are modified.
	 * @param type $record - Record to be modified.
	 */
	protected function handleKeepHistory($record) {				
		// Cloning the record.
		$oldRecord = clone $record;
		$oldRecord['to'] = new MongoDate();
		// This throws an exception if fails.
		$this->collection->save($oldRecord);
	}
	
	/**
	 * If user requested to keep the lines, all records in the lines collection are
	 * updated according to the user request.
	 */
	protected function handleKeepLines() {
		$keepLinesFieldsArray = Billrun_Factory::config()->getConfigValue('subscribers.keep_lines');
		$keepLinesUpdate = array();
		$keepLinesQuery = array();
		// Check if there are updated values for 'keep_lines'
		foreach ($this->recordToSet as $key=>$value) {
			if(isset($this->query[$key]) && in_array($key, $keepLinesFieldsArray)) {
				$keepLinesUpdate[$key] = $value;
				$keepLinesQuery[$key] = $this->query[$key];	
			}
		}
		
		// No need to apply keep lines logic
		if(empty($keepLinesQuery)) {
			return true;
		}
		
		$linesUpdate = array('$set' => $keepLinesUpdate);
		$options = array(
			'upsert' => false,
			'new' => false,
			'w' => 1,
		);
		$linesColl = Billrun_Factory::db()->linesCollection();
		return $linesColl->findAndModify($keepLinesQuery, $keepLinesUpdate, array(), $options, true);
	}
	
	/**
	 * Update a single subscriber record.
	 * @param Mongodloid_Entity $record - Subscriber record to update.
	 * @return boolean true if successful.
	 * @throws WriteConcernException
	 */
	protected function updateSubscriberRecord($record) {
		foreach ($this->recordToSet as $key => $value) {
			$record->collection($this->collection);

			// Check if the user requested to keep history.
			if($this->trackHistory) {
				$record['sid'] = $this->recordToSet['sid'];
				$record['msisdn'] = $this->recordToSet['msisdn'];
				
				// This throws an exception if fails.
				$this->handleKeepHistory($record);
			}

			if(!$record->set($key, $value)) {
				$error = "Failed to set values to entity";
				$this->reportError($error, Zend_Log::ALERT);
				return false;
			}

			// This throws an exception if fails.
			$this->collection->save($record);
		}
		
		return true;
	}
	
	/**
	 * Execute the action.
	 * @return data for output.
	 */
	public function execute() {
		$success = true;

		$updatedDocument = null;
		try {
			if($this->keepLines) {
				$this->handleKeepLines();
			}
			
			$cursor = $this->collection->query($this->query)->cursor();
			foreach ($cursor as $record) {
				$updatedDocument[] = $record->getRawData();
				if(!$this->updateSubscriberRecord($record)) {
					$success = false;
					break;
				}
				$updatedDocument[] = $record->getRawData();
			}
			
			if($this->keepBalances === FALSE) {
				// Close balances.
				$this->closeBalances($this->recordToSet['sid'], $this->recordToSet['aid']);
			}
			
		} catch (\Exception $e) {
			$error = 'failed storing in the DB got error : ' . $e->getCode() . ' : ' . $e->getMessage();
			$this->reportError($error, Zend_Log::ALERT);
			Billrun_Factory::log('failed saving request :' . print_r($this->recordToSet, 1), Zend_Log::ALERT);
			$success = false;
		}

		if(!$updatedDocument) {
			$success = false;
			$this->reportError("No subscribers found to update");
		}
		$outputResult = 
			array('status'  => ($success) ? (1) : (0),
				  'desc'    => $this->error,
				  'details' => ($updatedDocument) ? $updatedDocument : 'No results');
		return $outputResult;
	}

	/**
	 * Get the array of fields to be set in the update record from the user input.
	 * @return array - Array of fields to set.
	 */
	protected function getUpdateFields() {
		return array('imsi', 'msisdn', 'aid', 'sid', 'plan', 'language', 'service_provider', 'charging_type');
	}
	
	/**
	 * Set the values for the update record to be set.
	 * @param httpRequest $input - The input received from the user.
	 * @return true if successful false otherwise.
	 */
	protected function setUpdateRecord($input) {
		$jsonUpdateData = null;
		$update = $input->get('update');
		if(empty($update) || (!($jsonUpdateData = json_decode($update, true)))) {
			$error = "Update action does not have an update field!";
			$this->reportError($error, Zend_Log::ALERT);
			return false;
		}
		
		$updateFields = $this->getUpdateFields();
		
		// Get only the values to be set in the update record.
		// TODO: If no update fields are specified the record's to and from values will still be updated!
		foreach ($updateFields as $field) {
			// ATTENTION: This check will not allow updating to empty values which might be legitimate.
			if(isset($jsonUpdateData[$field]) && !empty($jsonUpdateData[$field])) {
				$this->recordToSet[$field] = $jsonUpdateData[$field];
			}
		}
		
		// THE 'from' FIELD IS SET AFTERWARDS WITH THE DATA FROM THE EXISTING RECORD IN MONGO.
		$this->recordToSet['to'] = new MongoDate(strtotime('+100 years'));
		
		return true;
	}
	
	/**
	 * Set all the query fields in the record with values.
	 * @param array $queryData - Data received.
	 * @return boolean true if success to set fields
	 */
	protected function setQueryFields($queryData) {
		$queryFields = $this->getQueryFields();
		
		// Array of errors to report if any occurs.
		$ret = false;
		
		// Get only the values to be set in the update record.
		// TODO: If no update fields are specified the record's to and from values will still be updated!
		foreach ($queryFields as $field) {
			// ATTENTION: This check will not allow updating to empty values which might be legitimate.
			if(isset($queryData[$field]) && !empty($queryData[$field])) {
				$this->query[$field] = $queryData[$field];
				$ret = true;
			}
		}
		
		return $ret;
	}
	
	/**
	 * Set the values for the query record to be set.
	 * @param httpRequest $input - The input received from the user.
	 * @return true if successful false otherwise.
	 */
	protected function setQueryRecord($input) {
		$jsonQueryData = null;
		$query = $input->get('query');
		if(empty($query) || (!($jsonQueryData = json_decode($query, true)))) {
			$error = "Update action does not have a query field!";
			$this->reportError($error, Zend_Log::ALERT);
			return false;
		}
		
		// If there were errors.
		if($this->setQueryFields($jsonQueryData) === FALSE) {
			$error = "Subscribers update received invalid query values in fields";
			$this->reportError($error, Zend_Log::ALERT);
			return false;
		}
		
		return true;
	}
	
	/**
	 * Parse the received request.
	 * @param type $input - Input received.
	 * @return true if valid.
	 * @todo Create a generic update class that implemnts this basic parse logic.
	 */
	public function parse($input) {
		if(!$this->setQueryRecord($input)) {
			return false;
		}
		
		if(!$this->setUpdateRecord($input)){
			return false;
		}
				
		// If keep_history is set take it.
		$this->trackHistory = $input->get('track_history');
		
		// If keep_balances is set take it.
		$this->keepBalances = $input->get('keep_balances');
		
		return true;
	}

}

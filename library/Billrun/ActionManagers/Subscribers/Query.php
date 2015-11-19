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
 * @todo This class is very similar to balances query, 
 * a generic query class should be created for both to implement.
 */
class Billrun_ActionManagers_Subscribers_Query extends Billrun_ActionManagers_Subscribers_Action{
	
	/**
	 * Field to hold the data to be written in the DB.
	 * @var type Array
	 */
	protected $subscriberQuery = array();
	
	/**
	 * If true then the query is a ranged query in a specific date.
	 * @var boolean 
	 */
	protected $queryInRange = false;
	
	/**
	 */
	public function __construct() {
		parent::__construct(array('error' => "Success deleting subscriber"));
	}
	
	/**
	 * Query the subscribers collection to receive data in a range.
	 */
	protected function queryRangeSubscribers() {
		try {
			$cursor = $this->collection->query($this->subscriberQuerys)->cursor();
			if(!$this->queryInRange) {
				$cursor->limit(1);
			}
			$returnData = array();
			
			// Going through the lines
			foreach ($cursor as $line) {
				$rawItem = $line->getRawData();
				$returnData[] = Billrun_Util::convertRecordMongoDatetimeFields($rawItem);
			}
		} catch (\Exception $e) {
			$error = 'failed quering DB got error : ' . $e->getCode() . ' : ' . $e->getMessage();
			$this->reportError($error, Zend_Log::ALERT);
			return null;
		}	
		
		return $returnData;
	}
	
	/**
	 * Execute the action.
	 * @return data for output.
	 */
	public function execute() {
		$returnData = 
			$this->queryRangeSubscribers();

		$success=true;
		// Check if the return data is invalid.
		if(!$returnData) {
			$returnData = array();
			$success=false;
		}
		
		$outputResult = 
			array('status'  => ($success) ? (1) : (0),
				  'desc'    => $this->error,
				  'details' => $returnData);
		return $outputResult;
	}
	
	/**
	 * Parse the to and from parameters if exists. If not execute handling logic.
	 * @param type $input - The received input.
	 */
	protected function parseDateParameters($input) {
		// Check if there is a to field.
		$to = $input->get('to');
		$from = $input->get('from');
		if($to && $from) {
			$this->subscriberQuery['to'] =
				array('$lte' => new MongoTimestamp($to));
			$this->subscriberQuery['from'] = 
				array('$gte' => new MongoTimestamp($from));
			$this->queryInRange = true;
		}
	}
	
	/**
	 * Parse the received request.
	 * @param type $input - Input received.
	 * @return true if valid.
	 */
	public function parse($input) {
		if(!$this->setQueryRecord($input)) {
			return false;
		}
		
		return true;
	}
	
	/**
	 * Set the values for the query record to be set.
	 * @param httpRequest $input - The input received from the user.
	 * @return true if successful false otherwise.
	 */
	protected function setQueryRecord($input) {
		$jsonData = null;
		$query = $input->get('query');
		if(empty($query) || (!($jsonData = json_decode($query, true)))) {
			$error = "Failed decoding JSON data";
			$this->reportError($error, Zend_Log::ALERT);
			return false;
		}
		
		$invalidFields = $this->setQueryFields($jsonData);
		
		// If there were errors.
		if(!empty($invalidFields)) {
			$error = "Subscribers query received invalid query values in fields: " . implode(',', $invalidFields);
			$this->reportError($error, Zend_Log::ALERT);
			return false;
		}
		
		return true;
	}
	
	/**
	 * Set all the query fields in the record with values.
	 * @param array $queryData - Data received.
	 * @return array - Array of strings of invalid field name. Empty if all is valid.
	 */
	protected function setQueryFields($queryData) {
		$queryFields = $this->getQueryFields();
		
		// Arrary of errors to report if any occurs.
		$invalidFields = array();
		
		// Get only the values to be set in the update record.
		foreach ($queryFields as $field) {
			if(isset($queryData[$field]) && !empty($queryData[$field])) {
				$this->subscriberQuery[$field] = $queryData[$field];
			} else {
				$invalidFields[] = $field;
			}
		}
		
		return $invalidFields;
	}
}

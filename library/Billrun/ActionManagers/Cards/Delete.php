<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * This is a delete parser for the use of Cards Delete action.
 *
 * @author Dori
 */
class Billrun_ActionManagers_Cards_Delete extends Billrun_ActionManagers_Cards_Action {

	/**
	 * Field to hold the data to be queried from the DB.
	 * @var type Array
	 */
	protected $query = array();
	protected $delete = array();

	/**
	 */
	public function __construct() {
		parent::__construct(array('error' => "Success deleting cards"));
	}

	/**
	 * Get the array of fields to be set in the query record from the user input.
	 * @return array - Array of fields to set.
	 */
	protected function getQueryFields() {
		return Billrun_Factory::config()->getConfigValue('cards.query_fields', array());
	}

	/**
	 * Get the array of fields to be set in the delete record from the user input.
	 * @return array - Array of fields to set.
	 */
	protected function getDeleteFields() {
		return Billrun_Factory::config()->getConfigValue('cards.delete_fields', array());
	}

	/**
	 * This function builds the query for the Cards Delete API after 
	 * validating existance of mandatory fields and their values.
	 * @param array $input - fields for query in Jason format. 
	 * @return Return false (and writes errLog) when fails to loocate 
	 * all needed fields and/or values for query and true when success.
	 */
	protected function queryProcess($input) {
		$queryFields = $this->getQueryFields();

		$jsonQueryData = null;
		$query = $input->get('query');
		if (empty($query) || (!($jsonQueryData = json_decode($query, true)))) {
			$error = "There is no query tag or query tag is empty!";
			$this->reportError($error, Zend_Log::ALERT);
			return false;
		}

		$errLog = array_diff($queryFields, array_keys($jsonQueryData));

		if (empty($jsonQueryData['batch_number'])) {
			$error = "Cannot delete ! All the following fields are missing or empty:" . implode(', ', $errLog);
			$this->reportError($error, Zend_Log::ALERT);
			return false;
		}
		
		if (isset($jsonQueryData['secret'])) {
			$jsonQueryData['secret'] = hash('sha512',$jsonQueryData['secret']);
		}

		$this->query = array();
		foreach ($queryFields as $field) {
			if (isset($jsonQueryData[$field])) {
				$this->query[$field] = $jsonQueryData[$field];
			}
		}

		return true;
	}

	protected function removeCreated($bulkOptions) {
		return Billrun_Factory::db()->cardsCollection()->remove($this->query, $bulkOptions);
	}
	
	/**
	 * Execute the action.
	 * @return data for output.
	 */
	public function execute() {
		$bulkOptions = array(
			'continueOnError' => true,
			'socketTimeoutMS' => 300000,
			'wTimeoutMS' => 300000,
			'w' => 1,
			'multiple' => 1
		);
		$exception = null;
		try {
			$deleteResult = $this->removeCreated($bulkOptions);
			$success = $deleteResult['ok'];
			$count = $deleteResult['n'];
		} catch (\Exception $e) {
			$exception = $e;
			$error = 'failed deleting from the DB got error : ' . $e->getCode() . ' : ' . $e->getMessage();
			$this->reportError($error, Zend_Log::ALERT);
			Billrun_Factory::log('failed deleting request :' . print_r($this->query, 1), Zend_Log::ALERT);
			$success = false;
		}

		$outputResult = array(
				'status' => ($success) ? (1) : (0),
				'desc' => $this->error,
				'details' => ($success) ? 
							 ('Deleted ' . $count . ' card(s)') : 
							 ('Failed deleting card(s) : ' . $exception->getCode() . ' : ' . $exception->getMessage())
		);

		return $outputResult;
	}

	/**
	 * Parse the received request.
	 * @param type $input - Input received.
	 * @return true if valid.
	 */
	public function parse($input) {

		if (!$this->queryProcess($input)) {
			return false;
		}

		return true;
	}

}

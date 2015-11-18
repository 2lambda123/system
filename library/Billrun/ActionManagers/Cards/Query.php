<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * This is a parser to be used by the cards action.
 *
 * @author Dori
 */
class Billrun_ActionManagers_Cards_Query extends Billrun_ActionManagers_Cards_Action {

	/**
	 * Field to hold the data to be written in the DB.
	 * @var type Array
	 */
	protected $cardsQuery = array();
	protected $limit = false;
	protected $page = false;

	/**
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Get the array of fields to be set in the query record from the user input.
	 * @return array - Array of fields to set.
	 */
	protected function getQueryFields() {
		return Billrun_Factory::config()->getConfigValue('cards.query_fields', array());
	}

	/**
	 * This function builds the query for the Cards Update API after 
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
			Billrun_Factory::log("There is no query tag or query tag is empty!", Zend_Log::ALERT);
			return false;
		}

		$errLog = array_diff($queryFields, array_keys($jsonQueryData));

		if (!empty($errLog) && count($errLog) == count($queryFields)) {
			Billrun_Factory::log("Cannot query ! All the following fields are missing or empty:" . implode(', ', $errLog), Zend_Log::ALERT);
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

	/**
	 * Execute the action.
	 * @return data for output.
	 */
	public function execute() {

		$skip = $this->page * $this->limit;
		$success = true;

		try {
			$cursor = $this->collection->query($this->query)->cursor()->skip($skip)->limit($this->limit);
			$returnData = array();

			// Going through the lines
			foreach ($cursor as $line) {
				$rawItem = $line->getRawData();
				unset($rawItem['secret']);
				$returnData[] = Billrun_Util::convertRecordMongoDatetimeFields($rawItem, array('to', 'creation_time'));
			}
		} catch (\Exception $e) {
			Billrun_Factory::log('failed quering DB got error : ' . $e->getCode() . ' : ' . $e->getMessage(), Zend_Log::ALERT);
			$success = false;
			$returnData = array();
		}

		$outputResult = array(
				'status' => ($success) ? (1) : (0),
				'desc' => ($success) ? ('success') : ('Failed querying cards'),
				'details' => $returnData
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

		$page = $input->get('page');
		$this->page = (!empty($page)) ? ($page) : (Billrun_Factory::config()->getConfigValue('api.cards.query.page', 0));
		$size = $input->get('size');
		$this->limit = (!empty($size)) ? ($size) : (Billrun_Factory::config()->getConfigValue('api.cards.query.size', 10000));

		return true;
	}

}

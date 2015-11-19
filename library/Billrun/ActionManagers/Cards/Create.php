<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Parser to be used by the cards action
 *
 * @package  cards
 * @since    4.0
 * @author   Dori
 */
class Billrun_ActionManagers_Cards_Create extends Billrun_ActionManagers_Cards_Action {

	/**
	 * Field to hold the data to be written in the DB.
	 * @var type Array
	 */
	protected $cards = array();
	protected $inner_hash;
	
	/**
	 */
	public function __construct() {
		parent::__construct(array('error' =>"Success creating cards"));
	}

	/**
	 * Get the array of fields to be inserted in the create record from the user input.
	 * @return array - Array of fields to be inserted.
	 */
	protected function getCreateFields() {
		return Billrun_Factory::config()->getConfigValue('cards.create_fields', array());
	}

	/**
	 * This function builds the create for the Cards creation API after 
	 * validating existance of field and that they are not empty.
	 * @param array $input - fields for insertion in Jason format. 
	 * @return Return false (and writes errLog) when fails to loocate 
	 * all needed field and/or values for insertion and true when success.
	 */
	protected function createProcess($input) {
		$createFields = $this->getCreateFields();
		$jsonCreateDataArray = null;
		$create = $input->get('cards');

		if (empty($create) || (!($jsonCreateDataArray = json_decode($create, true)))) {
			$error = "There is no create tag or create tag is empty!";
			$this->reportError($error, Zend_Log::ALERT);
			return false;
		}

		if ($jsonCreateDataArray !== array_values($jsonCreateDataArray)) {
			$jsonCreateDataArray = array($jsonCreateDataArray);
		}

		$this->inner_hash = md5(serialize($jsonCreateDataArray));		
		foreach ($jsonCreateDataArray as $jsonCreateData) {
			$oneCard = array();
			foreach ($createFields as $field) {
				if (!isset($jsonCreateData[$field])) {
					$error = "Field: " . $field . " is not set!";
					$this->reportError($error, Zend_Log::ALERT);
					return false;
				}
				$oneCard[$field] = $jsonCreateData[$field];
			}

			$oneCard['secret'] = hash('sha512',$oneCard['secret']);
			$oneCard['to'] = new MongoDate(strtotime($oneCard['to']));			
			$oneCard['creation_time'] = new MongoDate(strtotime($oneCard['creation_time']));
			$oneCard['inner_hash'] = $this->inner_hash;

			$this->cards[] = $oneCard;
		}

		return true;
	}

	/**
	 * Clean the inner hash from the cards in the mongo
	 * @param type $bulkOptions - Options for bulk insert in mongo db.
	 * @return type
	 */
	protected function cleanInnerHash($bulkOptions) {
		$updateQuery = array('inner_hash' => $this->inner_hash);	
		$updateValues = array('$unset' => array('inner_hash'=>1));			
		$updateOptions = array_merge($bulkOptions, array('multiple' => 1));
		return Billrun_Factory::db()->cardsCollection()->update($updateQuery, $updateValues, $updateOptions);
	}
	
	/**
	 * Remove the created cards due to error.
	 * @param type $bulkOptions - Options used for bulk insert to the mongo db.
	 * @return type
	 */
	protected function removeCreated($bulkOptions) {
		$removeQuery = array('inner_hash' => $this->inner_hash);
		return Billrun_Factory::db()->cardsCollection()->remove($removeQuery, $bulkOptions);
	}
	
	/**
	 * Execute the action. 
	 * @return data for output.
	 */
	public function execute() {
		$success = false;
		$bulkOptions = array(
			'continueOnError' => true,
			'socketTimeoutMS' => 300000,
			'wTimeoutMS' => 300000,
			'w' => 1,
		);
		$exception = null;
		try {
			$res = Billrun_Factory::db()->cardsCollection()->batchInsert($this->cards, $bulkOptions);
			$success = $res['ok'];
			$count = $res['nInserted'];
		} catch (\Exception $e) {
			$exception = $e;
			$error = 'failed storing in the DB got error : ' . $e->getCode() . ' : ' . $e->getMessage();
			$this->reportError($error, Zend_Log::ALERT);
			Billrun_Factory::log('failed saving request :' . print_r($this->cards, 1), Zend_Log::ALERT);
			$success = false;
			$res = $this->removeCreated($bulkOptions);
		}

		if ($success) {
			$res = $this->cleanInnerHash($bulkOptions);
		}
		
		array_walk($this->cards, function (&$card, $idx) { 
			unset($card['secret']); 			
		});
			
		$outputResult = array(
				'status' => ($success) ? (1) : (0),
				'desc' => $this->error,
				'details' => ($success) ? 
							 (json_encode($this->cards)) : 
							 ('Failed storing cards in the data base : ' . $exception->getCode() . ' : ' . $exception->getMessage() . '. ' . $res['n'] . ' cards removed')
		);
		return $outputResult;
	}

	/**
	 * Parse the received request.
	 * @param type $input - Input received.
	 * @return true if valid.
	 */
	public function parse($input) {

		if (!$this->createProcess($input)) {
			return false;
		}

		return true;
	}

}

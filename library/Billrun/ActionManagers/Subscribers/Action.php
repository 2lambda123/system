<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * This is a prototype for a subscriber action.
 *
 * @author tom
 */
abstract class Billrun_ActionManagers_Subscribers_Action extends Billrun_ActionManagers_APIAction{

	protected $collection = null;
	
	/**
	 * Create an instance of the SubscibersAction type.
	 */
	public function __construct($defaultError) {
		parent::__construct($defaultError);
		$this->collection = Billrun_Factory::db()->subscribersCollection();
	}
	
	/**
	 * Parse a request to build the action logic.
	 * 
	 * @param request $request The received request in the API.
	 * @return true if valid.
	 */
	public abstract function parse($request);
	
	/**
	 * Execute the action logic.
	 * 
	 * @return true if sucessfull.
	 */
	public abstract function execute();
	
	/**
	 * Get the array of fields to be set in the query record from the user input.
	 * @return array - Array of fields to set.
	 */
	protected function getQueryFields() {
		return Billrun_Factory::config()->getConfigValue('subscribers.query_fields');	
	}
}

<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billapi subscribers model for subscribers entity
 *
 * @package  Billapi
 * @since    5.3
 */
class Models_Subscribers extends Models_Entity {

	protected function init($params) {
		parent::init($params);
		$this->update['type'] = 'subscriber';

		// TODO: move to translators?
		if (empty($this->before)) { // this is new subscriber
			$this->update['plan_activation'] = isset($this->update['from']) ? $this->update['from'] : new MongoDate();
		} else if (isset($this->before['plan_activation']) && isset($this->update['plan']) && isset($this->before['plan']) && $this->before['plan'] !== $this->update['plan']) { // plan was changed
			$this->update['plan_activation'] = isset($this->update['from']) ? $this->update['from'] : new MongoDate();
		} else { // plan was not changed
			$this->update['plan_activation'] = $this->before['plan_activation'];
		}

		//transalte to and from fields
		Billrun_Utils_Mongo::convertQueryMongoDates($this->update);
		if ($this->action == 'create' && !isset($this->update['to'])) {
			$this->update['to'] = new MongoDate(strtotime('+149 years'));
		}
		
		$this->verifyServices();
	}

	public function get() {
		$this->query['type'] = 'subscriber';
		return parent::get();
	}

	/**
	 * method to add entity custom fields values from request
	 * 
	 * @param array $fields array of field settings
	 */
	protected function getCustomFields() {
		$customFields = parent::getCustomFields();
		$accountFields = Billrun_Factory::config()->getConfigValue($this->collectionName . ".subscriber.fields", array());
		return array_merge($accountFields, $customFields);
	}
	
	public function getCustomFieldsPath() {
		return $this->collectionName . ".subscriber.fields";
	}

	/**
	 * Verify services are correct before update is applied to the subscription
	 */
	protected function verifyServices() {
		if (empty($this->update) || empty($this->update['services'])) {
			return FALSE;
		}
		
		foreach ($this->update['services'] as &$service) {
			if (gettype($service) == 'string') {
				$service = array('name' => $service);
			}
			if (gettype($service['from']) == 'string') {
				$service['from'] = new MongoDate(strtotime($service['from']));
			}
			if (empty($this->before)) { // this is new subscriber
				$service['from'] = isset($service['from']) && $service['from'] >= $this->update['from'] ? $service['from'] : $this->update['from'];
			}
			//Handle custom period services
			$serviceRate = new Billrun_Service(array('name'=>$service['name'],'time'=>$service['from']->sec));
			if (!empty($serviceRate) && !empty($servicePeriod = @$serviceRate->get('balance_period')) && $servicePeriod !== "default") {
				$service['to'] = new MongoDate(strtotime($servicePeriod, $service['from']->sec));
			}

			//to can't be more then the updated 'to' of the subscription
			$entityTo = isset($this->update['to']) ? $this->update['to'] : $this->getBefore()['to'];
			$service['to'] = !empty($service['to']) && $service['to'] <= $entityTo ? $service['to'] : $entityTo;
			if (!isset($service['service_id'])) {
				$service['service_id'] = hexdec(uniqid());
			}
		}
	}
	
		
	/**
	 * Return the key field
	 * 
	 * @return String
	 */
	protected function getKeyField() {
		return 'sid';
	}

	/**
	 * move from date of entity including change the previous entity to field
	 * 
	 * @return boolean true on success else false
	 */
	protected function moveEntry($edge = 'from') {
		if ($edge == 'from') {
			$otherEdge = 'to';
		} else { // $current == 'to'
			$otherEdge = 'from';
		}
		if (!isset($this->update[$edge])) {
			$this->update = array(
				$edge => new MongoDate()
			);
		}

		if (($edge == 'from' && $this->update[$edge]->sec >= $this->before[$otherEdge]->sec) || ($edge == 'to' && $this->update[$edge]->sec <= $this->before[$otherEdge]->sec)) {
			throw new Billrun_Exceptions_Api(0, array(), 'Requested start date greater than or equal to end date');
		}

		$this->checkMinimumDate($this->update, $edge);

		$keyField = $this->getKeyField();

		if ($edge == 'from') {
			$query = array(
				$keyField => $this->before[$keyField],
				$otherEdge => array(
					'$lte' => $this->before[$edge],
				)
			);
			$sort = -1;
			$rangeError = 'Requested start date is less than previous end date';
		} else {
			$query = array(
				$keyField => $this->before[$keyField],
				$otherEdge => array(
					'$gte' => $this->before[$edge],
				)
			);
			$sort = 1;
			$rangeError = 'Requested end date is greater than next start date';
		}

		// previous entry on move from, next entry on move to
		$followingEntry = $this->collection->query($query)->cursor()
			->sort(array($otherEdge => $sort))
			->current();

		if (!empty($followingEntry) && !$followingEntry->isEmpty() && (
			($edge == 'from' && $followingEntry[$edge]->sec > $this->update[$edge]->sec) ||
			($edge == 'to' && $followingEntry[$edge]->sec < $this->update[$edge]->sec)
			)
		) {
			throw new Billrun_Exceptions_Api(0, array(), $rangeError);
		}


		if ($edge == 'from' && $this->before['plan_activation']->sec == $this->before['from']->sec) {
			$this->update['plan_activation'] = $this->update[$edge];
		}

		if ($edge == 'to' && isset($this->before['deactivation_date']->sec) && $this->before['deactivation_date']->sec == $this->before[$edge]->sec) {
			$this->update['deactivation_date'] = $this->update[$edge];
		}

		foreach ($this->before['services'] as $key => $service) {
			if ($service[$edge]->sec == $this->before[$edge]->sec) {
				$this->update["services.$key.$edge"] = $this->update[$edge];
			}
		}

		$status = $this->dbUpdate($this->query, $this->update);
		if ($edge == 'from' && $followingEntry->isEmpty()) {
			$update = array_merge($this->update, array('aid'=>$this->before['aid']));
			$this->afterSubscriberAction($status, $update);
		}
		if (!isset($status['nModified']) || !$status['nModified']) {
			return false;
		}
		$this->trackChanges($this->query['_id']);

		if (!empty($followingEntry) && !$followingEntry->isEmpty()) {
			$update = array($otherEdge => new MongoDate($this->update[$edge]->sec));
			if ($edge == 'to' && isset($followingEntry['plan_activation']->sec) && $followingEntry['plan_activation']->sec == $this->before[$edge]->sec) {
				$update['plan_activation'] = $update[$otherEdge];
			}

			// currently hypothetical case
			if ($edge == 'from' && isset($followingEntry['deactivation_date']->sec) && $followingEntry['deactivation_date']->sec == $this->before[$edge]->sec) {
				$update['deactivation_date'] = $update[$otherEdge];
			}

			foreach ($followingEntry['services'] as $key => $service) {
				if ($service[$otherEdge]->sec == $followingEntry[$otherEdge]->sec) {
					$update["services.$key.$otherEdge"] = $update[$otherEdge];
				}
			}
			$this->setQuery(array('_id' => $followingEntry['_id']->getMongoID()));
			$this->setUpdate($update);
			$this->setBefore($followingEntry);
			return $this->update();
		}
		return true;
	}

	public function close() {
		if (empty($this->update)) {
			$this->update = array();
		}
		if (isset($this->update['to'])) {
			$this->update['deactivation_date'] = $this->update['to'];
		} else {
			$this->update['to'] = $this->update['deactivation_date'] = new MongoDate();
		}
		return parent::close();
	}

	/**
	 * future entity was removed - need to update the to of the previous change
	 */
	protected function reopenPreviousEntry() {
		if (!$this->previousEntry->isEmpty()) {
			$this->setQuery(array('_id' => $this->previousEntry['_id']->getMongoID()));
			$update = array(
				'to' => $this->before['to'],
			);
			if (isset($this->before['deactivation_date']) && $this->before['deactivation_date']->sec == $this->before['to']->sec) {
				$update['deactivation_date'] = $this->before['to'];
			}
			$this->setUpdate($update);
			$this->setBefore($this->previousEntry);
			return $this->update();
		}
		return TRUE;
	}

	/**
	 * method to get the db command that run on close and new operation
	 * 
	 * @return array db update command
	 */
	protected function getCloseAndNewPreUpdateCommand() {
		$ret = parent::getCloseAndNewPreUpdateCommand();
		if (isset($this->before['deactivation_date'])) {
			$ret['$unset'] = array('deactivation_date' => 1);
		}
		return $ret;
	}
	
	/**
	 * Deals with changes need to be done after subscriber create/closeAndNew/move in specific cases.
	 * 
	 * @param array $status - Insert Status.
	 * 
	 */
	protected function afterSubscriberAction($status, $update) {
		if (isset($status['ok']) && $status['ok']) {
			$query['type'] = 'account';
			$query['aid'] = $update['aid'];
			$account = $this->collection->query($query)->cursor()->sort(array('from' => 1))->limit(1)->current();
			if ($account->isEmpty()) {
				Billrun_Factory::log("There isn't an account matching the subscriber.", Zend_Log::ERR);
			}
			if (isset($update['from']) && isset($account['from']) && $update['from'] < $account['from']) {
				$account['from'] = $update['from'];
				$accountDetails = $account->getRawData();
				$query['_id'] = $accountDetails['_id'];
				$this->dbUpdate($query, $accountDetails);
			}
		}
		return;
	}

	protected function insert(&$data) {
		$status = parent::insert($data);
		$this->afterSubscriberAction($status, $data);
	}

	public function create() {
		if (empty($this->update['to'])) {
			$this->update['to'] = new MongoDate(strtotime('+149 years'));
		}
		if (empty($this->update['deactivation_date'])) {
			$this->update['deactivation_date'] = $this->update['to'];
		}
		parent::create();
	}
	
	/**
	 * method to keep maintenance of subscriber fields.
	 * 
	 * @param MongoCursor $revisions array of the subscriber revisions
	 */
	protected function fixSubscriberFields($revisions) {
		$needUpdate = array();
		$previousRevision = array();
		$indicator = 0; 
		$plansDeactivation = array();
		$previousPlan = '';
		$subscriberDeactivation = $revisions->sort(array('to' => -1))->current()['to'];
		foreach ($revisions as $revision) {
			$revisionsArray[] = $revision->getRawData();
		}
		$sortedByFrom = array_reverse($revisionsArray);
		foreach ($sortedByFrom as &$revision) {
			$revisionId = $revision['_id']->{'$id'};
			if (empty($revision['deactivation_date']) || $subscriberDeactivation != $revision['deactivation_date']) {
				$needUpdate[$revisionId]['deactivation_date'] = $subscriberDeactivation;
			}
			$currentPlan = $revision['plan'];
			if ($currentPlan != $previousPlan && (empty($previousRevision) || $previousRevision['to'] == $revision['from']) || 
				(isset($previousRevision['to']) && $previousRevision['to'] != $revision['from'])) {
				$previousPlan = $currentPlan;
				$planActivation = $revision['from'];
				$planDeactivation = $revision['to'];
				$indicator += 1;
			}
			if (empty($revision['plan_activation']) || $planActivation != $revision['plan_activation']) {
				$needUpdate[$revisionId]['plan_activation'] = $planActivation;
			}
			$futureDeactivation = $revision['to'];
			if ($planDeactivation < $futureDeactivation) {
				$planDeactivation = $futureDeactivation;
			}
			$revision['indicator'] = $indicator;
			$plansDeactivation[$indicator] = $planDeactivation;
			$previousRevision = $revision;
		}
	
		foreach($plansDeactivation as $index => $deactivationDate) {
			foreach($sortedByFrom as $revision2) {
				$revisionId = $revision2['_id']->{'$id'};
				if ($revision2['indicator'] == $index && (!isset($revision2['plan_deactivation']) || $revision2['plan_deactivation'] != $deactivationDate)) {
					$needUpdate[$revisionId]['plan_deactivation'] = $deactivationDate;
				}
			}
		}

		foreach ($needUpdate as $revisionId => $updateValue) {
			$update = array();
			$query = array('_id' => new MongoId($revisionId));
			foreach ($updateValue as $field => $value) {
				$update['$set'][$field] = $value;
			}
			$this->collection->update($query, $update);
		}
	}
	
	/**
	 * get all revisions of a subscriber.
	 * 
	 * @param int $entity subscriber revision.
	 * @param int $aid - account id 
	 */
	protected function getSubscriberRevisions($entity, $aid) {
		$query = array();
		foreach (Billrun_Util::getFieldVal($this->config['duplicate_check'], []) as $fieldName) {
			$query[$fieldName] = $entity[$fieldName];
		}
		$query['aid'] = $aid;
		$revisions = $this->collection->query($query)->cursor();
		return $revisions;
	}
	
	protected function fixEntityFields($entity) {
		if (is_null($entity)) { // create action
			$update['$set']['plan_activation'] = $this->update['from'];
			$update['$set']['plan_deactivation'] = $update['$set']['deactivation_date'] = $this->update['to'];
			$this->collection->update(array('_id' => $this->update['_id']), $update);
			return;
		}
		$revisions = $this->getSubscriberRevisions($entity, $entity['aid']);
		$this->fixSubscriberFields($revisions);
		if ($entity['aid'] != $this->update['aid']) {
			$revisions = $this->getSubscriberRevisions($entity, $this->update['aid']);
			$this->fixSubscriberFields($revisions);
		}
	}
	
	public function permanentChange() {
		unset($this->update['plan_activation']);
		unset($this->update['type']);
		parent::permanentChange();
	}
}

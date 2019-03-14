<?php

/**
 * @package	Billing
 * @copyright	Copyright (C) 2012-2019 BillRun Technologies Ltd. All rights reserved.
 * @license	GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Plugin to keep API's backward compatible with V3
 *
 * @package  Application
 * @subpackage Plugins
 * @since    5.9
 */
class v3TranslatorPlugin extends Billrun_Plugin_BillrunPluginBase {
	
	protected $loadedConfigs = [];
	
	protected $configs = [];
	
	public function shouldTranslate($request) {
		if ($request instanceof Yaf_Request_Http) {
			return $request->get('translate', $request->getParam('translate', false));
		}
		return Billrun_Util::getIn($request, 'translate', false);
	}


	public function beforeBillApiRunOperation($collection, $action, &$request) {
		if (!$this->shouldTranslate($request)) {
			return;
		}
		
		if (in_array($action, ['get', 'uniqueget'])) {
			$this->translateGetRequest($collection, $request);
		}
	}
	
	public function afterBillApi($collection, $action, $request, &$output) {
		if (!$this->shouldTranslate($request)) {
			return;
		}
		
		if ($collection == 'plans') {
			$this->enrichPlansResponse($output);
		}
		
		$strip = $this->getCompundParam($request->get('strip', false), false);
		if ($strip) {
			$output = $this->stripResults($output, $params['strip']);
		}
	}
	
	protected function translateGetRequest($collection, &$request) {
		if (in_array($collection, ['rates', 'plans'])) {
			$query = json_decode($request['query'], JSON_OBJECT_AS_ARRAY);
			$query['hidden_from_api'] = false;
			$request['query'] = json_encode($query);
		}
	}
	
	protected function enrichPlansResponse(&$output) {
		$this->loadBillApiConfig('services');
		foreach ($output->details as &$plan) {
			$services = $this->getPlanServices($plan);
			$billableOptions = [];
			$nonBillableOptions = [];
			foreach ($services as $service) {
				$serviceName = $service['name'];
				$billable = Billrun_Util::getIn($service, 'billable', true);
				if ($billable) {
					$billableOptions[$serviceName] = $service;
				} else {
					$nonBillableOptions[$serviceName] = $service;
				}
			}
			$plan['options'] = $billableOptions;
			$plan['not_billable_options'] = $nonBillableOptions;
		}
	}
	
	protected function getPlanServices($plan) {
		$servicesNames = Billrun_Util::getIn($plan, 'include.services', []);
		$collection = 'services';
		$action = 'uniqueget';
		$query = [
			'name' => ['$regex' => implode('|', $servicesNames) ],
		];
		$params = [
			'request' => [
				'collection' => $collection,
				'action' => $action,
				'query' => json_encode($query),
			],
			'settings' => $this->getActionConfig($collection, $action),
		];
		$action = new Models_Action_Uniqueget($params);
		return $action->execute();
	}
	
	protected function loadBillApiConfig($collection) {
		if (in_array($collection, $this->loadedConfigs)) {
			return;
		}
		Billrun_Factory::config()->addConfig(APPLICATION_PATH . '/conf/modules/billapi/' . $collection . '.ini');
		$this->loadedConfigs[] = $collection;
	}
	
	protected function getActionConfig($collection, $action) {
		$configVar = 'billapi.' . $collection . '.' . $action;
		if (!isset($this->configs[$configVar])) {
			$this->configs[$configVar] = Billrun_Factory::config()->getConfigValue($configVar, []);
		}
		return $this->configs[$configVar];
	}
	
	/**
	 * copied from Plans/Rates API for BC
	 * 
	 * @param type $results
	 * @param type $strip
	 * @return type
	 * TODO: This function is found in the project multiple times, should be moved to a better location.
	 */
	protected function stripResults($results, $strip) {
		$stripped = array();
		foreach ($strip as $field) {
			foreach ($results as $result) {
				if (isset($result[$field])) {
					if (is_array($result[$field])) {
						$stripped[$field] = array_merge(isset($stripped[$field]) ? $stripped[$field] : array(), $result[$field]);
					} else {
						$stripped[$field][] = $result[$field];
					}
				}
			}
		}
		return $stripped;
	}

	/**
	 * process a compund http parameter (an array)
	 * @param type $param the parameter that was passed by the http;
	 * @return type
	 */
	protected function getCompundParam($param, $retParam = array()) {
		if (isset($param)) {
			$retParam = $param;
			if ($param !== FALSE) {
				if (is_string($param)) {
					$retParam = json_decode($param, true);
				} else {
					$retParam = (array) $param;
				}
			}
		}
		return $retParam;
	}
	
	public function beforeGetLinesData($request, &$linesRequestQueries) {
		$find = &$linesRequestQueries['find'];
		$options = &$linesRequestQueries['options'];
		
		if (!empty($request['zone_grouping_translate'])) {
			$zone_groupings = Billrun_Util::findInArray($find, '$or.*.zone_grouping.$in.*', null, true);
//			Billrun_Factory::log(print_R($zone_groupings, 1));
			foreach ($zone_groupings as $zone_grouping) {
				foreach ($zone_grouping as $key => $value) {
					$in = array('arate' => array('$in' => $this->getRatesBDRefByZoneGrouping($value['zone_grouping']['$in'])));
					unset($find['$or'][$key]['zone_grouping']);
					$find['$or'][$key] = array_merge($find['$or'][$key], $in);
				}
			}
			$options['rate_fields'] = array('zone_grouping');
		}
	}
	
	protected function getRatesBDRefByZoneGrouping($zone_grouping) {
		$ratesIds = Billrun_Factory::db()->ratesCollection()->query(array('zone_grouping' => array('$in' => $zone_grouping)))->cursor()->fields(array('_id' => 1))->setRawReturn(true);
		$retRates = array();
		foreach ($ratesIds as $id) {
			$retRates[] = MongoDBRef::create('rates', $id['_id']);
		}
		return $retRates;
	}
	
	public function afterTranslateCustomerAggregatorData($aggregator, &$translatedData) {
		$ret = [];
		$data = $aggregator->getData();
		$aid = $aggregator->getAid() ?: -1;
		$passthrough = $data['services'];
		$passthrough['aid'] = $aid;
		
		foreach ($data['services'] as $sub) {
			$planDates = [];
			$services = [];
			$id = [
				'aid' => $aid,
				'sid' => $sub['sid'] ?: -1,
				'plan' => $sub['next_plan'] ?: $sub['curr_plan'],
				'type' => 'subscriber',
				'address' => $sub['address'] ?: '',
				'services' => $services,
			];
			
			$ret[] = [
				'plan_dates' => $planDates,
				'card_token' => null,
				'id' => $id,
				'passthrough' => $passthrough,
			];
		}
		
		$translatedData = $ret;
	}

}

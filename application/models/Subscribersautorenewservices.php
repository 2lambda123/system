<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */


/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * This class is to hold the logic for the subscribers module.
 *
 * @package  Models
 * @subpackage Table
 * @since    4.0
 */
class SubscribersautorenewservicesModel extends TabledateModel{
	
	protected $subscribers_auto_renew_services_coll;
	
	/**
	 * constructor
	 * 
	 * @param array $params of parameters to preset the object
	 */
	public function __construct(array $params = array()) {
		$params['collection'] = Billrun_Factory::db()->subscribers_auto_renew_services;
		parent::__construct($params);
		$this->subscribers_auto_renew_services_coll = Billrun_Factory::db()->subscribers_auto_renew_servicesCollection();
		$this->search_key = "sid";
	}
	
	public function getTableColumns() {
		$columns = array(
			'aid' => 'AID',
			'sid' => 'SID',
			'interval' => 'Interval',
			'charging_plan_name' => 'Charging Plan Name',
			'charging_plan_external_id' => "Charging Plan External ID",
			'done' => 'Done',
			'remain' => 'Remain',
			'operators' => 'Operation',
			'last_renew_date' => 'Last Renew Date',
			'from' => 'From',
			'to' => 'To',
			'_id' => 'Id'
		);
		return $columns;
	}

	public function getSortFields() {
		$sort_fields = array(
			'aid' => 'AID',
			'sid' => 'SID',
			'interval' => 'Interval',
			'charging_plan_name' => 'Charging Plan Name',
			'charging_plan_external_id' => "Charging Plan External ID",
			'done' => 'Done',
			'remain' => 'Remain',
			'operators' => 'Operation',
			'last_renew_date' => 'Last Renew Date'
		);
		return array_merge($sort_fields, parent::getSortFields());
	}

	public function getFilterFields() {
		$planNames = array_unique(array_keys(Billrun_Plan::getPlans()['by_name']));
		$planNames = array_combine($planNames, $planNames);			

		$filter_fields = array(
			'sid' => array(
				'key' => 'sid',
				'db_key' => 'sid',
				'input_type' => 'number',
				'comparison' => 'equals',
				'display' => 'SID',
				'default' => '',
			),			
			'charging_plan_name' => array(
				'key' => 'charging_plan_name',
				'db_key' => 'charging_plan_name',
				'input_type' => 'multiselect',
				'comparison' => '$in',
				'ref_coll' => 'charging_plan_name',
				'ref_key' => 'name',
				'display' => 'Charging Plan',
				'values' => $planNames,
				'default' => array(),
			),
		);
		return array_merge($filter_fields, parent::getFilterFields());
	}

	public function getFilterFieldsOrder() {
		$filter_field_order = array(
			0 => array(
				'sid' => array(
					'width' => 2,
				),
				'charging_plan_name' => array(
					'width' => 2,
				)
			)
		);
		return array_merge($filter_field_order, parent::getFilterFieldsOrder());
	}	
	
	public function getProtectedKeys($entity, $type) {
		$parentKeys = parent::getProtectedKeys($entity, $type);
		return array_merge($parentKeys, 
						   array());
	}
}

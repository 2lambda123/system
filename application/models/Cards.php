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
 * This class is to hold the logic for the Cards module.
 *
 * @package  Models
 * @subpackage Table
 * @since    4.0
 */
class CardsModel extends TableModel {
	
	protected $cards_coll;
	
	/**
	 * constructor
	 * 
	 * @param array $params of parameters to preset the object
	 */
	public function __construct(array $params = array()) {
		$params['collection'] = Billrun_Factory::db()->cards;
		parent::__construct($params);
		$this->cards_coll = Billrun_Factory::db()->cardsCollection();
		$this->search_key = "secret";
	}

	public function getTableColumns() {
		$columns = array(
			'batch_number' => 'Batch Number',
			'serial_number' => 'Serial Number',
			'charging_plan_external_id' => 'Charging Plan',
			'service_provider' => 'Service Provider',
			'status' => 'Status',
			'to' => 'To',
			'_id' => 'Id',
		);
		return $columns;
	}

	public function getSortFields() {
		$sort_fields = array(
			'batch_number' => 'Batch Number',
			'serial_number' => 'Serial Number',
			'charging_plan_external_id' => 'Charging Plan',
			'status' => 'Status',
			'service_provider' => 'Service Provider',
		);
		return array_merge($sort_fields, parent::getSortFields());
	}
	
	public function getFilterFields() {
		$planModel = new PlansModel();
		$names = $planModel->getData(array('type' => 'charging'));
		$planNames = array();
		foreach($names as $name) {
			$planNames[] = $name['name'];
		}
		
		$statuses = array('Idle' => 'Idle', 'Shipped' => 'Shipped', 'Active' => 'Active', 'Disqualified' => 'Disqualified', 'Stolen' => 'Stolen', 'Expired' => 'Expired', 'Used' => 'Used');
		$filter_fields = array(
			'batch_number' => array(
				'key' => 'batch_number',
				'db_key' => 'batch_number',
				'input_type' => 'text',
				'comparison' => 'contains',
				'display' => 'Batch Number',
				'default' => '',
			),
			'serial_number' => array(
				'key' => 'serial_number',
				'db_key' => 'serial_number',
				'input_type' => 'text',
				'comparison' => 'contains',
				'display' => 'Serial Number',
				'default' => '',				
			),
			'plan' => array(
				'key' => 'plan',
				'db_key' => 'current_plan',
				'input_type' => 'multiselect',
				'comparison' => '$in',
				'ref_coll' => 'plans',
				'ref_key' => 'name',
				'display' => 'Plan',
				'values' => $planNames,
				'default' => array(),
			),
			'status' => array(
				'key' => 'status',
				'db_key' => 'status',
				'input_type' => 'multiselect',
				'comparison' => '$in',
				'singleselect' => 1,
				'display' => 'Status',
				'values' => $statuses,
				'default' => array(),
			),			
			'service_provider' => array(
				'key' => 'service_provider',
				'db_key' => 'service_provider',
				'input_type' => 'text',
				'comparison' => 'contains',
				'display' => 'Service Provider',
				'default' => '',
			),
			'to' => array(
				'key' => 'to',
				'db_key' => 'to',
				'input_type' => 'date',
				'comparison' => '$lte',
				'display' => 'To',
				'default' => (new Zend_Date(strtotime("next month"), null, new Zend_Locale('he_IL')))->toString('YYYY-MM-dd HH:mm:ss'),
			),
		);
		return array_merge($filter_fields, parent::getFilterFields());
	}

	public function getFilterFieldsOrder() {
		$filter_field_order = array(
			array(
				'batch_number' => array(
					'width' => 2,
				),
				'serial_number' => array(
					'width' => 2,
				),
				'plan' => array(
					'width' => 2
				)
			),
			array(
				'service_provider' => array(
					'width' => 2
				),
				'status' => array(
					'width' => 2
				),
				'sid' => array(
					'width' => 2,
				),
				'to' => array(
					'width' => 2,
				)
			),
		);
		return $filter_field_order;
	}	
	
	public function getProtectedKeys($entity, $type) {
		$parentKeys = parent::getProtectedKeys($entity, $type);
		return array_merge(	array("_id"),
							$parentKeys,
							array()
						);
	}
	public function getHiddenKeys($entity, $type) {
		$parentKeys = parent::getHiddenKeys($entity, $type);
		return array_merge(	array("_id"),
							$parentKeys,
							array(
								"secret"
							)
						);
	}
}

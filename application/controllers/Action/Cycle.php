<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

class CycleAction extends Action_Base {
	
	
	protected $billing_cycle = null;
	/**
	 * method to execute the aggregate process
	 * it's called automatically by the cli main controller
	 */
	public function execute() {
	
		$possibleOptions = array(
			'type' => false,
			'stamp' => true,
			'page' => true,
			'size' => true,
			'fetchonly' => true,
		);

		if (($options = $this->_controller->getInstanceOptions($possibleOptions)) === FALSE) {
			return;
		}	
	
		$this->billing_cycle = Billrun_Factory::db()->billing_cycleCollection();
		do{
			$this->billing_cycle = Billrun_Factory::db()->billing_cycleCollection();
			$pid = pcntl_fork();	
			if ($pid == -1) {
				die('could not fork');
			} else if ($pid) {
					sleep(60);
					pcntl_wait($status);
			} else {		
				$this->_controller->addOutput("Loading aggregator");
				try {
					$aggregator = Billrun_Aggregator::getInstance($options);
				} catch (Exception $e) {
					Billrun_Factory::log()->log($e->getMessage(), Zend_Log::NOTICE);
					$aggregator = FALSE;
				}	
				$this->_controller->addOutput("Aggregator loaded");
				if ($aggregator) {
					$this->_controller->addOutput("Loading data to Aggregate...");
					$aggregator->load();
					if (!isset($options['fetchonly'])) {
						$this->_controller->addOutput("Starting to Aggregate. This action can take a while...");
						$aggregator->aggregate();
						$this->_controller->addOutput("Finish to Aggregate.");
					} else {
						$this->_controller->addOutput("Only fetched aggregate accounts info. Exit...");
					}
				} else {
					$this->_controller->addOutput("Aggregator cannot be loaded");
				}
				break;
			}
		} 
		while (Billrun_Aggregator_Customer::isBillingCycleOver($this->billing_cycle, $options['stamp'], (int)$options['size']) === FALSE);
	} 
	
	
	

	
}

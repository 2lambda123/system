<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of Tax
 *
 * @author eran
 */
abstract class Billrun_Calculator_Tax extends Billrun_Calculator {

	static protected $type = 'tax';
	
	protected $config = array();
	protected $nonTaxableTypes = array();

	public function __construct($options = array()) {
		parent::__construct($options);
		$this->config = Billrun_Factory::config()->getConfigValue('taxation',array());
		$this->nonTaxableTypes = Billrun_Factory::config('taxation.non_taxable_types', array());
	}

	public function updateRow($row) {
		Billrun_Factory::dispatcher()->trigger('beforeCalculatorUpdateRow', array(&$row, $this));
		$current = $row instanceof Mongodloid_Entity ? $row->getRawData() : $row;
		if (!$this->isLineTaxable($current)) {
			$newData = $current;
			$newData['final_charge'] = $newData['aprice'];
		} else {
			if( $problemField = $this->isLineDataComplete($current) ) {
				Billrun_Factory::log("Line {$current['stamp']} is missing/has illigeal value in fields ".  implode(',', $problemField). ' For calcaulator '.$this->getType() );
				return FALSE;
			}
			$subscriber = new Billrun_Subscriber_Db();
			$subscriber->load(array('sid'=>$current['sid'],'time'=>date('Ymd H:i:sP',$current['urt']->sec)));
			$account = new Billrun_Account_Db();
			$account->load(array('aid'=>$current['aid'],'time'=>date('Ymd H:i:sP',$current['urt']->sec)));
			$newData = $this->updateRowTaxInforamtion($current, $subscriber->getSubscriberData(),$account->getCustomerData());
		
			//If we could not find the taxing information.
			if($newData == FALSE) {
				return FALSE;
			}
		}
		
		if($row instanceof Mongodloid_Entity ) {
			$row->setRawData($newData);
		} else {
			$row = $newData;
		}
		$row['final_charge']  = $row['tax_data']['total_amount'] + $row['aprice'];
		Billrun_Factory::dispatcher()->trigger('afterCalculatorUpdateRow', array(&$row, $this));
		return $row;
	}

	/**
	 * stab function The  will probably  be no need to prepare data for taxing
	 * @param type $lines
	 * @return nothing
	 */
	public function prepareData($lines) { }

	
	//================================= Static =================================
	/**
	 *  Get  the  total amount with taxes  for a given line
	 * @param type $taxedLine a line *after* taxation  was applied to it.
	 * @return float the  price of the line including taxes
	 *				 or the same value if the tax could not be calcualted without the taxedLine
	 */
	public static function addTax($untaxedPrice, $taxedLine = NULL) {
		return $untaxedPrice + Billrun_Util::getFieldVal($taxedLine['tax_data']['tax_amount'],0);
	}

	/**
	 *  Remove the taxes from the total amount with taxes for a given line
	 * @param type $taxedLine a line *after* taxation  was applied to it.
	 * @return float the price of the line including taxes \
	 *				 or the same value if the tax could not be calcualted without the taxedLine
	 */
	public static function removeTax($taxedPrice, $taxedLine = NULL) {
		return $taxedPrice - Billrun_Util::getFieldVal($taxedLine['tax_data']['tax_amount'],0);
	}
	
	//================================ Protected ===============================	

	/**
	 * Retrive all queued lines except from those that are configured not to be retrived.
	 * @return type
	 */
	protected function getLines() {
		return $this->getQueuedLines( array( 'type' => array( '$nin' => $this->nonTaxableTypes ) ) );
	}

	public function getCalculatorQueueType() {
		return 'tax';
	}

	public function isLineLegitimate($line) {
		return empty($line['skip_calc']) || !in_array(static::$type, $line['skip_calc']);
	}	
	
	protected function isLineTaxable($line) {
		$rate = $this->getRateForLine($line);
		return  (!empty($line[Billrun_Calculator_Rate::DEF_CALC_DB_FIELD]) && @$rate['vatable'])
					|| 
				( $line['usaget'] == 'flat' && !isset($rate['vatable']) );
	}
	
	protected function isLineDataComplete($line) {
		$missingFields = array_diff( array('aid'), array_keys($line) );
		return empty($missingFields) ? FALSE : $missingFields;
	}

	/**
	 * Update the line/row with it related taxing data.
	 * @param array $line The line to update it data.
	 * @param array $subscriber  the subscriber that is associated with the line
	 * @return array updated line/row with the tax data
	 */
	abstract protected function updateRowTaxInforamtion($line, $subscriber, $account);
	
	protected function getRateForLine($line) {
		$rate = FALSE;
		if(!empty($line['arate'])) {
			$rate = @Billrun_Rates_Util::getRateByRef($line['arate'])->getRawData();
		} else {
			$flatRate = $line['type'] == 'flat' ? 
				new Billrun_Plan(array('name'=> $line['name'], 'time'=> $line['urt']->sec)) : 
				new Billrun_Service(array('name'=> $line['name'], 'time'=> $line['urt']->sec));
			$rate = $flatRate->getData();
		}
		return $rate;			
	}
}

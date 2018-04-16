<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing invoice view - helper for html template for invoice
 *
 * @package  Billing
 * @since    5.0
 */
class Billrun_View_Invoice extends Yaf_View_Simple {
	
	public $lines = array();
	protected $tariffMultiplier = array(
		'call' => 60,
		'incoming_call' => 60,
		'data' => 1024*1024
	);
	protected $destinationsNumberTransforms = array( '/B/'=>'*','/A/'=>'#','/^0+972/'=>'0');
	
	/*
	 * get and set lines of the account
	 */
	public function setLines($accountLines) {
		foreach ($accountLines as $line) {
			$sid = (string)$line['sid'];
			if (empty($this->lines[$sid])) {
				$this->lines[$sid] = array();
			}
			array_push($this->lines[$sid], $line instanceof Mongodloid_Entity ? $line : new Mongodloid_Entity($line));
		}
	}
	
	public function loadLines() {
		$lines_collection = Billrun_Factory::db()->linesCollection();
		$this->lines = array();
		$aid = $this->data['aid'];
		$billrun_key = $this->data['billrun_key'];
		$query = array('aid' => $aid, 'billrun' => $billrun_key);
		$accountLines = $lines_collection->query($query);
		$this->setLines($accountLines);
	}
	
	
	
	public function getLineUsageName($line) {
		$usageName = '';
		$rate = $this->getRateForLine($line);
		$typeMapping = array('flat' => array('rate'=> 'description','line'=>'name'), 
							 'service' => array('rate'=> 'description','line' => 'name'));
		
		if(in_array($line['type'],array_keys($typeMapping))) {			
			$usageName = isset($typeMapping[$line['type']]['rate']) ? 
								$rate[$typeMapping[$line['type']]['rate']] :
								ucfirst(strtolower(preg_replace('/_/', ' ',$line[$typeMapping[$line['type']]['line']])));
		} else {
			$usageName = !empty($line['description']) ?
							$line['description'] : 
							(!empty($rate['description']) ? 
								$rate['description'] :
								ucfirst(strtolower(preg_replace('/_/', ' ',$line['arate_key']))) );
		}
		return $usageName;
	}
	
	public function getAllDiscount($lines) {
		$discounts = array('lines' => array(), 'total'=> 0);
		foreach($lines as $subLines) {
			foreach($subLines as $line) {
				if($line['usaget'] == 'discount') {
					@$discounts['lines'][$this->getLineUsageName($line)] += $line['aprice'];
					@$discounts['total'] +=$line['aprice'];
				}
			}
		}
		return $discounts;
	}
	
	public function getLineUsageVolume($line) {
		$usagev = Billrun_Utils_Units::convertInvoiceVolume($line['usagev'], $line['usaget']);
		$unit = Billrun_Utils_Units::getInvoiceUnit($line['usaget']);
		$unitDisplay = Billrun_Utils_Units::getUnitLabel($line['usaget'], $unit);
		return (is_numeric($usagev) ? number_format($usagev, 2) : $usagev) . ' ' . $unitDisplay;
	}


	public function buildSubscriptionListFromLines($lines) {
		$subscriptionList = array();
		$typeNames = array_flip($this->details_keys);
		foreach($lines as $subLines) {
			foreach($subLines as $line) {
				if($line['usaget'] == 'discount') {
					
				}
				if(in_array($line['type'],$this->flat_line_types) && $line['aprice'] != 0 && $line['usaget'] != 'discount') {
					$rate = $this->getRateForLine($line);
					$flatData =  ($line['type'] == 'credit') ? $rate['rates']['call']['BASE']['rate'][0] : $rate;
					
					$line->collection(Billrun_Factory::db()->linesCollection());
					$name = $this->getLineUsageName($line);
					$key = $this->getLineAggregationKey($line, $rate, $name);
					$subscriptionList[$key]['desc'] = $name;	
					$subscriptionList[$key]['type'] = $typeNames[$line['type']];
					//TODO : HACK : this is an hack to add rate to the highcomm invoice need to replace is  with the actual logic once the  pricing  process  will also add the  used rates to the line pricing information.
					$subscriptionList[$key]['rate'] = max(@$subscriptionList[$key]['rate'],$this->getLineRatePrice($flatData,$line));
					@$subscriptionList[$key]['count']+= Billrun_Util::getFieldVal($line['usagev'],1);
					$subscriptionList[$key]['amount'] = Billrun_Util::getFieldVal($subscriptionList[$key]['amount'],0) + $line['aprice'];
					$subscriptionList[$key]['start'] = empty($line['start']) ? @$subscriptionList[$key]['start'] : $line['start'] ;
					$subscriptionList[$key]['end'] = empty($line['end']) ? @$subscriptionList[$key]['end'] : $line['end'] ;
					$subscriptionList[$key]['span'] = $this->getListItemSpan($subscriptionList[$key]);
				}
			}
		}
		return $subscriptionList;
	}
	
	public function currencySymbol() {
		return Billrun_Rates_Util::getCurrencySymbol(Billrun_Factory::config()->getConfigValue('pricing.currency','USD'));
	}
	
	protected function getLineRatePrice($rate, $line) {
		$pricePerUsage = 0;		
		if(isset($rate['price'][0]['price'])) {
			$priceByCycle = Billrun_Util::mapArrayToStructuredHash($rate['price'], array('from'));
			$pricePerUsage = $priceByCycle[empty($line['cycle']) ? 0 : $line['cycle']]['price'];
		} else {
			$pricePerUsage = $rate['price'];
		}
		return $pricePerUsage;
	}
	
	protected function getRateForLine($line) {
		$rate = FALSE;
		if(!empty($line['arate'])) {
			$rate = MongoDBRef::isRef($line['arate']) ? Billrun_Rates_Util::getRateByRef($line['arate']) : $line['arate'];
			$rate = $rate->getRawData();
		} else {
			$flatRate = $line['type'] == 'flat' ? 
				new Billrun_Plan(array('name'=> $line['name'], 'time'=> $line['urt']->sec)) : 
				new Billrun_Service(array('name'=> $line['name'], 'time'=> $line['urt']->sec));
			$rate = $flatRate->getData();
		}
		return $rate;			
	}
	
	protected function getLineAggregationKey($line,$rate,$name) {
		$key = $name;
		if($line['type'] == 'service' && $rate['quantitative']) {
			$key .= $line['usagev']. $line['sid'];
		}
		if(!empty($line['start'])) {
			$key .= date('ymd',$line['start']->sec);
		}
		if(!empty($line['end'])) {
			$key .=  date('ymd',$line['end']->sec);
		}
		if(!empty($line['cycle'])) {
			$key .= $line['cycle'];
		}
		return $key;
	}
	
	protected function getListItemSpan($item) {
		return (empty($item['start']) ? '' : 'Starting '.date(date($this->date_format,$item['start']->sec))) .
				(empty($item['start']) || empty($item['end']) ? '' : ' - ') .
				(empty($item['end'])   ? '' : 'Ending '.date(date($this->date_format,$item['end']->sec)));
	}

	public function getFormatedPrice($price,$precision = 2, $priceSymbol = '₪') {
		return "{$priceSymbol} ". number_format((isset($price) ? floatval($price): 0), $precision)  ;
	}
	
	public function getFormatedUsage($usage, $usaget, $showUnits = false, $precision = 0) {
		$usage = empty($usage) ? 0 :$usage;
		$unit = Billrun_Utils_Units::getInvoiceUnit($usaget);
		$volume = Billrun_Utils_Units::convertVolumeUnits( $usage , $usaget,  $unit);
		return (preg_match('/^[\d.]+$/', $volume) && $volume ?  number_format($volume,$precision) : $volume )." ". ($showUnits ? Billrun_Utils_Units::getUnitLabel($usaget, $unit) : '');
	}

	public function getRateTariff($rateName, $usaget) {
		if(!empty($rateName)) {
			$rate = Billrun_Rates_Util::getRateByName($rateName, $this->data['end_date']->sec);
			if(!empty($rate)) {
				$tariff = Billrun_Rates_Util::getTariff($rate, $usaget);
			
			}
		}
		return (empty($tariff) ? 0 : Billrun_Tariff_Util::getTariffForVolume($tariff, 0))  * Billrun_Util::getFieldVal($this->tariffMultiplier[$usaget], 1);
	}

	public function getPlanDescription($subscriberiptionData) {
		if(!empty($subscriberiptionData['plan'])) {
			$plan = Billrun_Factory::plan(array('name'=>$subscriberiptionData['plan'],'time'=>$this->data['end_date']->sec));
			return str_replace('[[NextPlanStage]]', date(Billrun_Base::base_dateformat, Billrun_Util::getFieldVal($subscriberiptionData['next_plan_price_tier'],new MongoDate())->sec), $plan->get('invoice_description'));
		}
		return "";
	}
	
	public function getBillrunKey() {
		return $this->data['billrun_key'];
	}
	
	public function shouldProvideDetails() {
		return !empty($this->data['attributes']['detailed_invoice']) || in_array($this->data['aid'],  Billrun_Factory::config()->getConfigValue('invoice_export.aid_with_detailed_invoices',array()));
	}
	
	public function getInvoicePhonenumber($rawNumber) {
		$retNumber = $rawNumber;
		
		foreach($this->destinationsNumberTransforms as $regex => $transform) {
			$retNumber = preg_replace($regex,$transform,$retNumber);
		}
		
		return $retNumber;
	}
}

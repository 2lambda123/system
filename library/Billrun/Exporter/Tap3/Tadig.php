<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2018 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing TAP3 exporter
 * According to Specification Version Number 3, Release Version Number 12 (3.12)
 *
 * @package  Billing
 * @since    2.8
 */
class Billrun_Exporter_Tap3_Tadig extends Billrun_Exporter_Asn1 {

	static protected $type = 'tap3';
	
	protected $vpmnTadig = '';
	protected $startTime = null;
	protected $timeZoneOffset = '';
	protected $timeZoneOffsetCode = '';
	protected $startTimeStamp = '';
	protected $numOfDecPlaces;
	
	public function __construct($options = array()) {
		parent::__construct($options);
		$this->vpmnTadig = $options['tadig'];
		$this->startTime = time();
		$this->timeZoneOffset = date($this->getConfig('datetime_offset_format', 'O'), $this->startTime);
		$this->timeZoneOffsetCode = intval($this->getConfig('datetime_offset_code', 0));
		$this->startTimeStamp = date($this->getConfig('datetime_format', 'YmdHis'), $this->startTime);
		$this->numOfDecPlaces = intval($this->getConfig('header.num_of_decimal_places'));
	}
	
	/**
	 * see parent::getFieldsMapping
	 */
	protected function getFieldsMapping($row) {
		$callEventDetail = $this->getCallEventDetail($row);
		return $this->getConfig(array('fields_mapping', $callEventDetail), array());
	}
	
	/**
	 * gets call event details to get correct mapping according to usage type
	 * 
	 * @param array $row
	 * @return string one of: MobileOriginatedCall/MobileTerminatedCall/SupplServiceEvent/ServiceCentreUsage/GprsCall/ContentTransaction/LocationService/MessagingEvent/MobileSession
	 * @todo implement for all types
	 */
	protected function getCallEventDetail($row) {
		switch ($row['type']) {
			case 'ggsn':
				return 'GprsCall';
			default:
				return '';
		}
	}

	protected function getFileName() { // TODO: implement
		return '/home/yonatan/Downloads/TDINDATISRGT00003_4';
	}

	protected function getQuery() { // TODO: fix query
		return array(
			'type' => 'ggsn',
			'imsi' => ['$exists' => 1],
		);
	}
	
	/**
	 * see parent::getHeader
	 */
	protected function getHeader() {
		return array(
			'BatchControlInfo' => array(
				'Sender' => $this->getConfig('header.sender'),
				'Recipient' => 'ISRGT',
				'FileSequenceNumber' => '00003',
				'FileCreationTimeStamp' => array(
					'LocalTimeStamp' => $this->startTimeStamp,
					'UtcTimeOffset' => $this->timeZoneOffset,
				),
				'TransferCutOffTimeStamp' => array(
					'LocalTimeStamp' => $this->startTimeStamp,
					'UtcTimeOffset' => $this->timeZoneOffset,
				),
				'FileAvailableTimeStamp' => array(
					'LocalTimeStamp' => $this->startTimeStamp,
					'UtcTimeOffset' => $this->timeZoneOffset,
				),
				'SpecificationVersionNumber' => intval($this->getConfig('header.version_number')),
				'ReleaseVersionNumber' => intval($this->getConfig('header.release_version_number')),
				'FileTypeIndicator' => $this->getConfig('header.file_type_indicator'),
			),
			'AccountingInfo' => array(
				'LocalCurrency' => $this->getConfig('header.local_currency'),
				'TapCurrency' => $this->getConfig('header.tap_currency'),
				'CurrencyConversionList' => array(
					array(
						'CurrencyConversion' => array(
							'ExchangeRateCode' => intval($this->getConfig('header.currency_conversion.exchange_rate_code')),
							'NumberOfDecimalPlaces' => intval($this->getConfig('header.currency_conversion.num_of_decimal_places')),
							'ExchangeRate' => intval($this->getConfig('header.currency_conversion.exchange_rate')),
						),
					),
				),
				'TapDecimalPlaces' => $this->numOfDecPlaces,
			),
			'NetworkInfo' => array(
				'UtcTimeOffsetInfoList' => array(
					array(
						'UtcTimeOffsetInfo' => array(
							'UtcTimeOffsetCode' => $this->timeZoneOffsetCode,
							'UtcTimeOffset' => $this->timeZoneOffset,
						),
					),
				),
				'RecEntityInfoList' => $this->getRecEntityInfoList(),
			),
		);
	}

	/**
	 * see parent::getFooter
	 */
	protected function getFooter() {
		$totalCharge = 0;
		$totalTax = 0;
		$totalDiscount = 0;
		$earliestUrt = null;
		$latestUrt = null;
		$dateFormat = $this->getConfig('datetime_format', 'YmdHis');
		foreach ($this->rawRows as $row) {
			$totalCharge += isset($row['aprice']) ? floatval($row['aprice']) * pow(10, $this->numOfDecPlaces) : 0;
			$totalTax += isset($row['tax']) ? floatval($row['tax']) * pow(10, $this->numOfDecPlaces) : 0;
			$urt = $row['urt']->sec;
			if (is_null($earliestUrt) || $urt < $earliestUrt) {
				$earliestUrt = $urt;
			}
			if (is_null($latestUrt) || $urt > $latestUrt) {
				$latestUrt = $urt;
			}
		}
		return array(
			'AuditControlInfo' => array(
				'EarliestCallTimeStamp' => array(
					'LocalTimeStamp' => date($dateFormat, $earliestUrt),
					'UtcTimeOffset' => $this->timeZoneOffset,
				),
				'LatestCallTimeStamp' => array(
					'LocalTimeStamp' => date($dateFormat, $latestUrt),
					'UtcTimeOffset' => $this->timeZoneOffset,
				),
				'TotalCharge' => $totalCharge,
				'TotalTaxValue' => $totalTax,
				'TotalDiscountValue' => $totalDiscount,
				'CallEventDetailsCount' => count($this->rawRows),
			),
		);
	}
	
	protected function loadRows() {
		parent::loadRows();
		$this->rowsToExport = array(
			'CallEventDetailList' => $this->rowsToExport,
		);
	}
	
	/**
	 * see parent::getDataToExport
	 */
	protected function getDataToExport() {
		$dataToExport = parent::getDataToExport();
		return array(
			'TransferBatch' => $dataToExport,
		);
	}
	
	protected function getRecEntityInfoList() {
		$ret = array();
		foreach ($this->rawRows as $row) {
			$recEntityInfo = $this->getRecEntityInformation($row);
			$found = !empty(array_filter($ret, function($ele) use($recEntityInfo) {
				foreach ($recEntityInfo as $key => $val) {
					if ($ele['RecEntityInformation'][$key] != $val) {
						return false;
					}
				}
				return true;
			}));
			if (!$found) {
				$ret[] = array(
					'RecEntityInformation' => $recEntityInfo,
				);
			}
		}
		return $ret;
	}
	
	protected function getRecEntityInformation($row) {
		switch ($row['type']) {
			case 'ggsn':
				$recEntityCode = 0; // TODO: get correct value
				$recEntityType = $this->getConfig('rec_entity_type.GGSN');
				$recEntityId = $row['ggsn_address'];
				break;
			default:
				$recEntityCode = $recEntityType = 0;
				$recEntityId = '';
		}
		return array(
			'RecEntityCode' => intval($recEntityCode),
			'RecEntityType' => intval($recEntityType),
			'RecEntityId' => $recEntityId,
		);
	}
	
	protected function getUtcTimeOffsetCode($row, $fieldMapping) {
		return $this->timeZoneOffsetCode;
	}
	
	protected function getRecEntityCodeList($row, $fieldMapping) {
		switch ($row['type']) {
			case 'ggsn':
				$recEntityCode = 0; // TODO: get correct value
				break;
			default:
				$recEntityCode = 0;
		}
		return array(
			array(
				'RecEntityCode' => $recEntityCode,
			),
		);
	}
	
	protected function getChargeInformationList($row, $fieldMapping) {
		switch ($row['type']) {
			case 'ggsn':
				$chargedItem = $this->getConfig('charged_item.volume_total_based_charge');
				$callTypeLevel1 = $this->getConfig('call_type_level_1.GGSN');
				$callTypeLevel2 = $this->getConfig('call_type_level_2.unknown');
				$callTypeLevel3 = $this->getConfig('call_type_level_3.unknown');
				$chargeType = $this->getConfig('charge_type.total_charge');
				$charge = $row['aprice'];
				$chargeableUnits = $row['usagev'];
				$chargedUnits = ceil($chargeableUnits / 1024) * 1024; // TODO: currentlty, no "rounded" volume field
				break;
			default:
				$callTypeLevel1 = $this->getConfig('call_type_level_1.unknown');
				$callTypeLevel2 = $this->getConfig('call_type_level_2.unknown');
				$callTypeLevel3 = $this->getConfig('call_type_level_3.unknown');
				$chargeType = $this->getConfig('charge_type.total_charge');
				$charge = $chargeableUnits = $chargedUnits = 0;
		}
		return array(
			array(
				'ChargeInformation' => array(
					'ChargedItem' => $chargedItem,
					'ExchangeRateCode' => 0, // TODO: get correct value from row
					'CallTypeGroup' => array(
						'CallTypeLevel1' => intval($callTypeLevel1),
						'CallTypeLevel2' => intval($callTypeLevel2),
						'CallTypeLevel3' => intval($callTypeLevel3),
					),
					'ChargeDetailList' => array(
						array(
							'ChargeDetail' => array(
								'ChargeType' => $chargeType,
								'Charge' => $charge,
								'ChargeableUnits' => $chargeableUnits,
								'ChargedUnits' => $chargedUnits,
								'ChargeDetailTimeStamp' => array(
									'LocalTimeStamp' => date($this->getConfig('datetime_format', 'YmdHis'), $row['urt']->sec),
									'UtcTimeOffsetCode' => $this->timeZoneOffsetCode,
								),
							),
						),
					),
				),
			),
		);
	}

}

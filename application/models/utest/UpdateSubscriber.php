<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Update subscriber model class
 *
 * @package  Models
 * @subpackage uTest
 * @since    4.0
 */
class UpdateSubscriberModel extends UtestModel {

	public function doTest() {
		$query_sid = (int) Billrun_Util::filter_var($this->controller->getRequest()->get('sid'), FILTER_VALIDATE_INT);
		$query_imsi = Billrun_Util::filter_var($this->controller->getRequest()->get('imsi'), FILTER_SANITIZE_STRING);
		$query_msisdn = Billrun_Util::filter_var($this->controller->getRequest()->get('msisdn'), FILTER_SANITIZE_STRING);
		
		
		$sid = (int) Billrun_Util::filter_var($this->controller->getRequest()->get('new_sid'), FILTER_VALIDATE_INT);
		$enable_sid = Billrun_Util::filter_var($this->controller->getRequest()->get('enable-new_sid'), FILTER_VALIDATE_INT);
		
		$imsi = Billrun_Util::filter_var($this->controller->getRequest()->get('new_imsi'), FILTER_SANITIZE_STRING);
		$enable_imsi = Billrun_Util::filter_var($this->controller->getRequest()->get('enable-new_imsi'), FILTER_SANITIZE_STRING);
		
		$msisdn = Billrun_Util::filter_var($this->controller->getRequest()->get('new_msisdn'), FILTER_SANITIZE_STRING);
		$enable_msisdn = Billrun_Util::filter_var($this->controller->getRequest()->get('enable-new_msisdn'), FILTER_SANITIZE_STRING);
		
		$aid = (int) Billrun_Util::filter_var($this->controller->getRequest()->get('aid'), FILTER_SANITIZE_STRING);
		$enable_aid = Billrun_Util::filter_var($this->controller->getRequest()->get('enable-aid'), FILTER_SANITIZE_STRING);
		
		$plan = Billrun_Util::filter_var($this->controller->getRequest()->get('plan'), FILTER_SANITIZE_STRING);
		$enable_plan = Billrun_Util::filter_var($this->controller->getRequest()->get('enable-plan'), FILTER_SANITIZE_STRING);
		
		$service_provider = Billrun_Util::filter_var($this->controller->getRequest()->get('service_provider'), FILTER_SANITIZE_STRING);
		$enable_service_provider = Billrun_Util::filter_var($this->controller->getRequest()->get('enable-service_provider'), FILTER_SANITIZE_STRING);
		
		$charging_type = Billrun_Util::filter_var($this->controller->getRequest()->get('charging_type'), FILTER_SANITIZE_STRING);
		$enable_charging_type = Billrun_Util::filter_var($this->controller->getRequest()->get('enable-charging_type'), FILTER_SANITIZE_STRING);
		
		$language = Billrun_Util::filter_var($this->controller->getRequest()->get('language'), FILTER_SANITIZE_STRING);
		$enable_lang = Billrun_Util::filter_var($this->controller->getRequest()->get('enable-enable-language'), FILTER_SANITIZE_STRING);
		
				
		$track_history = (bool) Billrun_Util::filter_var($this->controller->getRequest()->get('track_history'), FILTER_SANITIZE_STRING);
		$enable_track_history = Billrun_Util::filter_var($this->controller->getRequest()->get('enable-track_history'), FILTER_SANITIZE_STRING);
				
		$keep_balances = (bool) Billrun_Util::filter_var($this->controller->getRequest()->get('keep_balances'), FILTER_SANITIZE_STRING);
		$enable_keep_balances = Billrun_Util::filter_var($this->controller->getRequest()->get('enable-keep_balances'), FILTER_SANITIZE_STRING);
				
		$keep_lines = (bool) Billrun_Util::filter_var($this->controller->getRequest()->get('keep_lines'), FILTER_SANITIZE_STRING);
		$enable_keep_lines = Billrun_Util::filter_var($this->controller->getRequest()->get('enable-keep_lines'), FILTER_SANITIZE_STRING);
		

		$imsi = array_map('trim', explode("\n", trim($imsi)));
		if(count($imsi) == 1) {
			$imsi = $imsi[0];
		}
		
		$params = array(
			'query' => array(
				'sid' => $query_sid,
				'imsi' => $query_imsi,
				'msisdn' => $query_msisdn,
			),
			'update' => array(
				'sid' => array(
					'enable' => $enable_sid,
					'value' => $sid
				),
				'imsi' => array(
					'value' => $imsi,
					'enable' => $enable_imsi
				),
				'msisdn' => array(
					'value' => $msisdn,
					'enable' => $enable_msisdn
				),
				'aid' => array(
					'value' => $aid,
					'enable' => $enable_aid
				),
				'plan' => array(
					'value' => $plan,
					'enable' => $enable_plan
				),
				'service_provider' => array(
					'value' => $service_provider,
					'enable' => $enable_service_provider
				),
				'charging_type' => array(
					'value' => $charging_type,
					'enable' => $enable_charging_type
				),
				'language' => array(
					'value' => $language,
					'enable' => $enable_lang
				),
				'track_history' => array(
					'value' => $track_history,
					'enable' => $enable_track_history
				),
				'keep_balances' => array(
					'value' => $keep_balances,
					'enable' => $enable_keep_balances
				),
				'keep_lines' => array(
					'value' => $keep_lines,
					'enable' => $enable_keep_lines
				),
			)
		);

		$data = $this->getRequestData($params);
		$this->controller->sendRequest($data, 'subscribers');
	}

	protected function getRequestData($params) {
	
		$query = array();
		foreach ($params['query'] as $key => $value) {
			if(!empty($value)){
				$query[$key] = $value;
			}
		}
		
		$update = array();
		foreach ($params['update'] as $key => $param) {
			if($param['enable'] === 'on'){
				$update[$key] = $param['value'];
			}
		}

		$request = array(
			'method' => 'update',
			'query' => json_encode($query),
			'update' => json_encode($update),
		);
		return $request;
	}

}

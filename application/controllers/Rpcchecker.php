<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * 
 *
 * @package  calculator
 * @since    0.5
 */
class RPCCheckerController extends Yaf_Controller_Abstract {

	protected $cases = array();

	public function indexAction() {
		$this->initCases();
		$this->checkCases();
		die();
	}

	protected function initCases() {
		$this->cases = array(
			array(
				'account_id' => 4555208,
				'date' => '2013-10-24 23:59:59',
				'subscribers' => array(
					'275778' => array(
						'curr_plan' => 'LARGE',
						'next_plan' => 'NULL'
					),
					'275779' => array(
						'curr_plan' => 'LARGE',
						'next_plan' => 'NULL'
					),
					'275780' => array(
						'curr_plan' => 'LARGE',
						'next_plan' => 'NULL'
					),
				),
			),
			array(
				'account_id' => 14426,
				'date' => '2013-10-24 23:59:59',
				'subscribers' => array(
					'285336' => array(
						'curr_plan' => 'LARGE',
						'next_plan' => 'NULL'
					),
				),
			),
			array(
				'account_id' => 3515736,
				'date' => '2013-10-24 23:59:59',
				'subscribers' => array(
					'428931' => array(
						'curr_plan' => 'SMALL',
						'next_plan' => 'BIRTHDAY'
					),
				),
			),
			array(
				'account_id' => 23658,
				'date' => '2013-10-24 23:59:59',
				'subscribers' => array(
					'485035' => array(
						'curr_plan' => 'SMALL',
						'next_plan' => 'SMALL'
					),
				),
			),
			array(
				'account_id' => 6676268,
				'date' => '2013-10-24 23:59:59',
				'subscribers' => array(
					'348861' => array(
						'curr_plan' => 'SMALL',
						'next_plan' => 'SMALL'
					),
					'348864' => array(
						'curr_plan' => 'SMALL',
						'next_plan' => 'SMALL'
					),
					'348858' => array(
						'curr_plan' => 'NULL',
						'next_plan' => 'NULL'
					),
				),
			),
			array(
				'account_id' => 193,
				'date' => '2013-10-24 23:59:59',
				'subscribers' => array(
					'91249' => array(
						'curr_plan' => 'NULL',
						'next_plan' => 'NULL'
					),
					'454672' => array(
						'curr_plan' => 'LARGE',
						'next_plan' => 'LARGE'
					),
					'91248' => array(
						'curr_plan' => 'LARGE',
						'next_plan' => 'LARGE'
					),
				),
			),
			array(
				'account_id' => 6055249,
				'date' => '2013-10-30 23:59:59',
				'subscribers' => array(
					'498730' => array(
						'curr_plan' => 'LARGE',
						'next_plan' => 'LARGE'
					),
					'499532' => array(
						'curr_plan' => 'LARGE',
						'next_plan' => 'LARGE'
					),
					'499679' => array(
						'curr_plan' => 'SMALL',
						'next_plan' => 'BIRTHDAY'
					),
				),
			),
			array(
				'account_id' => 28110,
				'date' => '2013-10-24 23:59:59',
				'subscribers' => array(
					'116962' => array(
						'curr_plan' => 'SMALL',
						'next_plan' => 'SMALL'
					),
					'116963' => array(
						'curr_plan' => 'SMALL',
						'next_plan' => 'SMALL'
					),
					'116964' => array(
						'curr_plan' => 'NULL',
						'next_plan' => 'NULL'
					),
					'142344' => array(
						'curr_plan' => 'NULL',
						'next_plan' => 'NULL'
					),
					'185975' => array(
						'curr_plan' => 'LARGE',
						'next_plan' => 'LARGE'
					),
					'185980' => array(
						'curr_plan' => 'SMALL',
						'next_plan' => 'SMALL'
					),
				),
			),
			array(
				'account_id' => 6055249,
				'date' => '2013-10-24 23:59:59',
				'subscribers' => array(),
			),
			array(
				'account_id' => 8294532,
				'date' => '2013-10-24 23:59:59',
				'subscribers' => array(),
			),
			array(
				'account_id' => 384664,
				'date' => '2013-10-24 23:59:59',
				'subscribers' => array(
					'492367' => array(
						'curr_plan' => 'NULL',
						'next_plan' => 'NULL',
					),
					'352700' => array(
						'curr_plan' => 'NULL',
						'next_plan' => 'NULL',
					),
					'398808' => array(
						'curr_plan' => 'LARGE',
						'next_plan' => 'LARGE'
					),
					'398781' => array(
						'curr_plan' => 'LARGE',
						'next_plan' => 'SMALL'
					),
					'352699' => array(
						'curr_plan' => 'BIRTHDAY',
						'next_plan' => 'SMALL'
					),
				),
			),
		);
	}

	protected function checkCases() {
		$subscriber = Billrun_Factory::subscriber();
		foreach ($this->cases as $case) {
			$data = $subscriber->getList(0, 1, $case['date'], $case['account_id']);
			if (!$this->checkOutput($case, $data)) {
				echo 'Wrong output for ' . $case['account_id'] . ', ' . $case['date'] . ".</br>Expected output:</br>" . json_encode($case) . "</br></br>";
			}
		}
		echo "Finished";
	}

	protected function checkOutput($case, $data) {
		if (!empty($case['subscribers'])) {
			if (count($case['subscribers']) != count($data[$case['account_id']])) {
				return false;
			}
			foreach ($data[$case['account_id']] as $subscriber) {
				if (!array_key_exists($subscriber->sid, $case['subscribers'])) {
					return false;
				}
				if ($subscriber->plan != $case['subscribers'][$subscriber->sid]['curr_plan']) {
					return false;
				}
				if ($subscriber->getNextPlanName() != $case['subscribers'][$subscriber->sid]['next_plan']) {
					return false;
				}
			}
		} else if (!empty($data)) {
			return false;
		}
		return true;
	}

}

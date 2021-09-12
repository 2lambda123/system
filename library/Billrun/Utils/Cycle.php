<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

class Billrun_Utils_Cycle {
	public static function isTimeWithinCycle($time,$cycleKey) {
		$cycle = new Billrun_DataTypes_CycleTime($cycleKey);
		$timestamp = $time instanceof MongoDate ? $time->sec : $time;
		return $cycle->start() <= $timestamp && $timestamp <= $cycle->end();
	}

	public static function shouldBeInCycle($entryConfig,\Billrun_DataTypes_CycleTime $cycle) {
		$activationDate= !empty($entryConfig['activation_date']) ? $entryConfig['activation_date']->sec : $cycle->start();
		$startDate = empty(($entryConfig['recurrence']['start'])) 	?
									$activationDate :
									strtotime(date("Y-{$entryConfig['recurrence']['start']}-01 00:00:00", $activationDate));
		$startDateStr = date(Billrun_Base::base_dateformat,($startDate < $cycle->end() ? $startDate : $cycle->end()));
		$endDateStr = date(Billrun_Base::base_dateformat,($startDate < $cycle->end() ? $cycle->end() : $startDate));

		return empty($entryConfig['recurrence']['frequency']) ||
				(Billrun_Utils_Time::getMonthsDiff($startDateStr, $endDateStr) % $entryConfig['recurrence']['frequency']) == 0 ||
				(!empty($entryConfig['deactivation_date']) && $entryConfig['deactivation_date']->sec <= $cycle->end() &&  $entryConfig['deactivation_date']->sec >= $cycle->start() ) ||
				(!empty($entryConfig['plan_deactivation']) && $entryConfig['plan_deactivation']->sec <= $cycle->end() &&  $entryConfig['plan_deactivation']->sec >= $cycle->start() );

	}

	public static function addMonthsToCycleKey($cycleKey,$monthsToAdd) {
		$year = substr($cycleKey,0,4);
		$month = substr($cycleKey,4,2);
		$year = $year + (ceil(($monthsToAdd + $month ) / 12  ) - 1);
		$month = ( (($monthsToAdd + $month + 11) % 12) + 1 );

		return $year . str_pad($month,2,'0',STR_PAD_LEFT) . substr($cycleKey,6);

	}

	public static  function substractMonthsFromCycleKey($cycleKey,$monthsTosubstract) {

		return self::addMonthsToCycleKey($cycleKey,-$monthsTosubstract);
	}

	public static function getRecurrenceOffset($recurrenceConfig, $cycleKey,$activationDate = null) {
		$activationDate = ($activationDate ?
						$activationDate :
						Billrun_Billingcycle::getStartTime($cycleKey));
		$startDate = ($recurrenceConfig['start'] ?
						date("Y-{$recurrenceConfig['start']}-01", $activationDate) :
						date(Billrun_Base::base_dateformat,$activationDate));

		return Billrun_Utils_Time::getMonthsDiff($startDate, date(Billrun_Base::base_dateformat, Billrun_Billingcycle::getEndTime($cycleKey))) % $recurrenceConfig['frequency'];
	}
}

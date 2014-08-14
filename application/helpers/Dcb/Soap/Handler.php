<?php

/**
 * Dcb Soap Handler Class 
 * 
 * 
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero Public License version 3 or later; see LICENSE.txt
 */

/**
 * Dcb_Soap_Handler Class Definition
 */
class Dcb_Soap_Handler {

	const GOOGLE_RESULT_CODE_SUCCESS = 'SUCCESS';
	const GOOGLE_RESULT_CODE_GENERAL_FAILURE = 'GENERAL_FAILURE';
	const GOOGLE_RESULT_CODE_RETRIABLE_ERROR = 'RETRIABLE_ERROR';
	
	public function __call($name, $arguments) {
		if (method_exists($this, 'do' . $name)) {
			return call_user_func(array($this, 'do' . $name), $arguments);
		} else {
			return false;
		}
	}

	public function doEcho($params) {
		$echoResponse = new stdclass;
		$echoResponse->Version = $params[0]->Version;
		$echoResponse->CorrelationId = $params[0]->CorrelationId;
		$echoResponse->Result = self::GOOGLE_RESULT_CODE_SUCCESS;
		$echoResponse->OriginalMessage = $params[0]->Message;
		return $echoResponse;
	}
	
	public function CancelNotification($params) {
		return array();
	}

}


<?php
/**
 * @package         Gsmodem
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Gsmodem AT command interface class
 *
 * @package  Gsmodem
 * @since    0.1
 */
class Gsmodem_M400StateMapping  extends Gsmodem_StateMapping {
	/**
	 * Mapping result  to the state to should lead to.
	 */
	protected $resultToStateMapping = array(
		self::OUT_CALL_STATE => array(
			'NO ANSWER' => self::IDLE_STATE,
			'BUSY' => self::IDLE_STATE,
			'ERROR' => self::IDLE_STATE,
			'NO CARRIER' => self::IDLE_STATE,
		),
		self::IN_CALL_STATE => array(
			'NO ANSWER' => self::IDLE_STATE,
			'BUSY' => self::IDLE_STATE,
			'NO CARRIER' => self::IDLE_STATE,
		),
		self::CALLING_STATE => array(
			'VOICE CALL\: BEGIN' => self::OUT_CALL_STATE,
			'NO CARRIER' => self::IDLE_STATE,
			'ERROR' => self::IDLE_STATE,
			'RING' => self::RINGING_STATE,
			'BUSY' => self::IDLE_STATE,
		),
		self::RINGING_STATE => array(
			'RING' => self::RINGING_STATE,
			'NO CARRIER' => self::IDLE_STATE,
			'MISSED_CALL' => self::IDLE_STATE,
		),
		self::ANSWERING_STATE => array(
			'VOICE CALL\: BEGIN' => self::IN_CALL_STATE,
			'OK' => self::IN_CALL_STATE,
			'NO CARRIER' => self::IDLE_STATE,			
			'ERROR' => self::IDLE_STATE,
		),
		self::HANGING_UP_STATE => array(
			'OK' => self::IDLE_STATE,
			'RING' => self::RINGING_STATE,
			'ERROR' => self::IDLE_STATE,
		),
		self::IDLE_STATE => array(
			'ERROR' => self::IDLE_STATE,
			'\+CLIP\:' => self::RINGING_STATE,
			'RING' => self::RINGING_STATE,
		),
	);
	
	public function __construct() {
		parent::__construct();
		$this->commandToStateMapping[static::IN_CALL_STATE]['AT\+CVHUP'] = static::HANGING_UP_STATE;
		$this->commandToStateMapping[static::ANSWERING_STATE]['AT\+CVHUP'] = static::HANGING_UP_STATE;
		$this->commandToStateMapping[static::CALLING_STATE]['AT\+CVHUP'] = static::HANGING_UP_STATE;
		$this->commandToStateMapping[static::IN_CALL_STATE]['AT\+CVHUP'] = static::HANGING_UP_STATE;
		$this->commandToStateMapping[static::OUT_CALL_STATE]['AT\+CVHUP'] = static::HANGING_UP_STATE;
	}
	
}
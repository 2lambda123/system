<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * class for mongo not equal operator translator.
 *
 * @author tom
 */
class Admin_MongoOperatorTranslators_NotEqual extends Admin_MongoOperatorTranslators_Translator {
	
	/**
	 * Return the mongo operator string.
	 * @return string - Mongo operator string for this class.
	 */
	public function getOperator() {
		return '$ne';
	}
}

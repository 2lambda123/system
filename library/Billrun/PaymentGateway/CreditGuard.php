<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * This class represents a payment gateway
 *
 * @since    5.2
 */
class Billrun_PaymentGateway_CreditGuard extends Billrun_PaymentGateway {

	protected $conf;
	protected $billrunName = "CreditGuard";
	protected $subscribers;
	protected $pendingCodes = "/$^/";
	protected $completionCodes = "/^000$/";

	protected function __construct() {
		if (Billrun_Factory::config()->isProd()) {
			// TODO: define 'live' url for payment gateway.
		} else {  // test/dev environment
			$this->EndpointUrl = "https://kupot1t.creditguard.co.il/xpo/Relay";
		}
		$this->subscribers = Billrun_Factory::db()->subscribersCollection();
	}

	public function updateSessionTransactionId() {
		$url_array = parse_url($this->redirectUrl);
		$str_response = array();
		parse_str($url_array['query'], $str_response);
		$this->transactionId = $str_response['txId'];
	}

	protected function buildPostArray($aid, $returnUrl, $okPage) {
		$credentials = $this->getGatewayCredentials();
		$this->conf['amount'] = (int) Billrun_Factory::config()->getConfigValue('CG.conf.amount');
		$this->conf['cg_gateway_url'] = Billrun_Factory::config()->getConfigValue('CG.conf.gateway_url');
		$this->conf['aid'] = $aid;
		$this->conf['ok_page'] = $okPage;
		$this->conf['return_url'] = $returnUrl;
		$today = new MongoDate();
		$account = $this->subscribers->query(array('aid' => (int) $aid, 'from' => array('$lte' => $today), 'to' => array('$gte' => $today), 'type' => "account"))->cursor()->current();
		$this->conf['language'] = isset($account['pay_page_lang']) ? $account['pay_page_lang'] : "ENG";

		return $post_array = array(
			'user' => $credentials['user'],
			'password' => $credentials['password'],
			/* Build Ashrait XML to post */
			'int_in' => '<ashrait>                                      
							<request>
								 <version>1000</version>
								 <language>' . $this->conf['language'] . '</language>
								 <dateTime></dateTime>
								 <command>doDeal</command>
								 <doDeal>
										 <successUrl>' . $this->conf['ok_page'] . '</successUrl>
										  <terminalNumber>' . $credentials['terminal_id'] . '</terminalNumber>
										  <mainTerminalNumber/>
										  <cardNo>CGMPI</cardNo>
										  <total>' . $this->conf['amount'] . '</total>
										  <transactionType>Debit</transactionType>
										  <creditType>RegularCredit</creditType>
										  <currency>ILS</currency>
										  <transactionCode>Phone</transactionCode>
										  <authNumber/>
										  <numberOfPayments/>
										  <firstPayment/>
										  <periodicalPayment/>
										  <validation>TxnSetup</validation>
										  <dealerNumber/>
										  <user>something</user>
										  <mid>' . (int) $credentials['mid'] . '</mid>
										  <uniqueid>' . time() . rand(100, 1000) . '</uniqueid>
										  <mpiValidation>Normal</mpiValidation>
										  <email>someone@creditguard.co.il</email>
										  <clientIP/>
										  <customerData>
										   <userData1>' . $this->conf['aid'] . '</userData1>
										   <userData2/>
										   <userData3/>
										   <userData4/>
										   <userData5/>
										   <userData6/>
										   <userData7/>
										   <userData8/>
										   <userData9/>
										   <userData10/>
										  </customerData>
								 </doDeal>
							</request>
						   </ashrait>'
		);
	}

	protected function updateRedirectUrl($result) {
		if (function_exists("simplexml_load_string")) {
			if (strpos(strtoupper($result), 'HEB')) {
				$result = iconv("utf-8", "iso-8859-8", $result);
			}
			$xmlObj = simplexml_load_string($result);

			if (isset($xmlObj->response->doDeal->mpiHostedPageUrl)) {

				$this->redirectUrl = $xmlObj->response->doDeal->mpiHostedPageUrl;
			} else {
				die('<strong>Can\'t Create Transaction</strong> <br />' .
					'Error Code: ' . $xmlObj->response->result . '<br />' .
					'Message: ' . $xmlObj->response->message . '<br />' .
					'Addition Info: ' . $xmlObj->response->additionalInfo);
			}
		} else {
			die("simplexml_load_string function is not support, upgrade PHP version!");
		}
	}

	protected function buildTransactionPost($txId, $additionalParams) {
		$params = $this->getGatewayCredentials();
		$params['txId'] = $txId;
		$params['tid'] = $params['terminal_id'];

		return $this->buildInquireQuery($params);
	}

	public function getTransactionIdName() {
		return "txId";
	}

	protected function getResponseDetails($result) {
		if (function_exists("simplexml_load_string")) {
			if (strpos(strtoupper($result), 'HEB')) {
				$result = iconv("utf-8", "iso-8859-8", $result);
			}
			$xmlObj = simplexml_load_string($result);
			// Example to print out status text
			if (!isset($xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->result))
				return false;

			$this->saveDetails['card_token'] = (string) $xmlObj->response->inquireTransactions->row->cardId;
			$this->saveDetails['card_expiration'] = (string) $xmlObj->response->inquireTransactions->row->cardExpiration;
			$this->saveDetails['aid'] = (int) $xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->customerData->userData1;
			$this->saveDetails['personal_id'] = (string) $xmlObj->response->inquireTransactions->row->personalId;

			return true;
		} else {
			die("simplexml_load_string function is not support, upgrade PHP version!");
		}
	}

	protected function buildSetQuery() {
		return array(
			'payment_gateway.active' => array(
				'name' => $this->billrunName,
				'card_token' => (string) $this->saveDetails['card_token'],
				'card_expiration' => (string) $this->saveDetails['card_expiration'],
				'personal_id' => (string) $this->saveDetails['personal_id'],
				'transaction_exhausted' => true,
				'generate_token_time' => new MongoDate(time())
			)
		);
	}

	public function getDefaultParameters() {
		$params = array("user", "password", "terminal_id", "mid");
		return $this->rearrangeParametres($params);
	}

	public function authenticateCredentials($params) {
		$params['txId'] = 1;
		$authArray = $this->buildInquireQuery($params);
		$authString = http_build_query($authArray);
		if (function_exists("curl_init")) {
			$result = Billrun_Util::sendRequest($this->EndpointUrl, $authString, Zend_Http_Client::POST, array('Accept-encoding' => 'deflate'), null, 0);
		}
		if (strpos(strtoupper($result), 'HEB')) {
			$result = iconv("utf-8", "iso-8859-8", $result);
		}
		$xmlObj = simplexml_load_string($result);
		$codeResult = (string) $xmlObj->response->result;
		if ($codeResult == "405") {
			return false;
		} else {
			return true;
		}
	}

	public function pay($gatewayDetails) {
		$paymentArray = $this->buildPaymentRequset($gatewayDetails);
		$paymentString = http_build_query($paymentArray);
		if (function_exists("curl_init")) {
			$result = Billrun_Util::sendRequest($this->EndpointUrl, $paymentString, Zend_Http_Client::POST, array('Accept-encoding' => 'deflate'), null, 0);
		}
		if (strpos(strtoupper($result), 'HEB')) {
			$result = iconv("utf-8", "iso-8859-8", $result);
		}
		$xmlObj = simplexml_load_string($result);
		$codeResult = (string) $xmlObj->response->result;
		return $codeResult;
	}

	protected function buildPaymentRequset($gatewayDetails) {
		$credentials = $this->getGatewayCredentials();
		$gatewayDetails['amount'] = $this->convertAmountToSend($gatewayDetails['amount']);

		return $post_array = array(
			'user' => $credentials['user'],
			'password' => $credentials['password'],
			/* Build Ashrait XML to post */
			'int_in' => '<ashrait>
								<request>
								<command>doDeal</command>
								<requestId>23468</requestId>
								<version>1001</version>
								<language>Eng</language>
								<mayBeDuplicate>0</mayBeDuplicate>
									<doDeal>
										<terminalNumber>' . $credentials['terminal_id'] . '</terminalNumber>
										<cardId>' . $gatewayDetails['card_token'] . '</cardId>
										<cardExpiration>' . $gatewayDetails['card_expiration'] . '</cardExpiration>
										<creditType>RegularCredit</creditType>
										<currency>' . $gatewayDetails['currency'] . '</currency>
										<transactionCode>Phone</transactionCode>
										<transactionType>Debit</transactionType>
										<total>' . $gatewayDetails['amount'] . '</total>
										<validation>AutoComm</validation>
									</doDeal>
								</request>
						</ashrait>'
		);
	}

	public function verifyPending($txId) {
		
	}

	public function hasPendingStatus() {
		return false;
	}
	
	protected function buildInquireQuery($params){
		return array(
			'user' => $params['user'],
			'password' => $params['password'],
			/* Build Ashrait XML to post */
			'int_in' => '<ashrait>
							<request>
							 <language>HEB</language>
							 <command>inquireTransactions</command>
							 <inquireTransactions>
							  <terminalNumber>' . $params['tid'] . '</terminalNumber>
							  <mainTerminalNumber/>
							  <queryName>mpiTransaction</queryName>
							  <mid>' . (int)$params['mid'] . '</mid>
							  <mpiTransactionId>' . $params['txId'] . '</mpiTransactionId>
							  <mpiValidation>Token</mpiValidation>
							  <userData1/>
							  <userData2/>
							  <userData3/>
							  <userData4/>
							  <userData5/>
							 </inquireTransactions>
							</request>
					   </ashrait>'
		);
	}
	
	protected function isRejected($status) {
		return (!$this->isCompleted($status) && !$this->isPending($status));
	}
	
	protected function convertAmountToSend($amount) {
		$amount = round($amount, 2);
		return $amount * 100;
	}

	protected function isNeedAdjustingRequest(){
		return true;
	}
	
	protected function isUrlRedirect() {
		return true;
	}
	
	protected function isHtmlRedirect() {
		return false;
	}
	
	public function isCustomerBasedCharge() {
		return false;
	}
	
	protected function needRequestForToken() {
		return true;
	}
}

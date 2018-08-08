<?php

//$dir = '/home/idan/projects/billrun';
defined('APPLICATION_PATH') || define('APPLICATION_PATH', $dir);
require_once(APPLICATION_PATH . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'config.php');
$app = new Yaf_Application(BILLRUN_CONFIG_PATH);
$app->bootstrap();
Yaf_Loader::getInstance(APPLICATION_PATH . '/application/modules/Billapi')->registerLocalNamespace("Models");

/**
 * Update Credit Guard voucher number to relavent bills.
 * @param array $filePath the path of the file to parse
 */
function restoreVoucherNumberCG($filePath) {
	echo 'Running Restore Voucher CG...' . PHP_EOL;
	$billsColl = Billrun_Factory::db()->billsCollection();
	$transactions = $fields = array(); $i = 0;
	$csvFile = @fopen($filePath, "r");
	if (!$csvFile) {
		echo 'Missing File, please check path' . PHP_EOL;
	}
	if ($csvFile) {
		while (($row = fgetcsv($csvFile, 4096)) !== false) {
			if (empty($fields)) {
				$fields = $row;
				continue;
			}
			foreach ($row as $key => $value) {
				if (!in_array($fields[$key], ['Shovar', 'ID'])) {
					continue;
				}
				if ($value == '') {
					continue;
				}
				$transactions[$i][$fields[$key]] = $value;
			}
			$i++;
		}
		if (!feof($csvFile)) {
			echo "Error: unexpected fgets() fail\n";
		}
		fclose($csvFile);
	}
	foreach ($transactions as $transaction) {
		$billsColl->update(array('payment_gateway.transactionId' => $transaction['ID']), array('$set' => array('vendor_response.payment_identifier' => str_pad($transaction['Shovar'], 6, '0', STR_PAD_LEFT))));
	}
}

$filePath = $argv[3];
restoreVoucherNumberCG($filePath);

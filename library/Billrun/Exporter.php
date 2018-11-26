<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2018 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing abstract exporter class
 *
 * @package  Billing
 * @since    5.8
 */
abstract class Billrun_Exporter extends Billrun_Base {

	/**
	 * Type of exporter
	 *
	 * @var string
	 */
	static protected $type = 'exporter';
	
	const SEQUENCE_NUM_INIT = 1;
	
	/**
	 * configuration for internal use of the exporter
	 * 
	 * @var array
	 */
	protected $config = array();
	
	protected $logCollection = null;
	protected $sequenceNum = null;
	protected $exportTime = null;
	protected $exportStamp = null;
	protected $logStamp = array();
	protected $collection = null;
	protected $query = array();
	protected $rowsToExport = array();
	protected $headerToExport = null;
	protected $footerToExport = null;
	protected $rawRows = array();

	public function __construct($options = array()) {
		parent::__construct($options);
		$this->config = $options;
		$this->exportTime = time();
		$this->exportTime = strtotime('2018-11-18 10:29:04');//TODO: REMOVE!
		$this->exportStamp = $this->getExportStamp();
		$this->query = $this->getQuery();
		$this->logCollection = Billrun_Factory::db()->logCollection();
	}
	
	public static function getInstance() {
		$args = func_get_args();
		$stamp = md5(static::class . serialize($args));
		if (isset(self::$instance[$stamp])) {
			return self::$instance[$stamp];
		}

		$args = $args[0];
		$exportGeneratorSettings = Billrun_Factory::config()->getExportGeneratorSettings($args['type']);
		if (!$exportGeneratorSettings) {
			Billrun_Factory::log("Can't get configurarion: " . print_R($args, 1), Zend_Log::EMERG);
			return false;
		}
		$params = array_merge($exportGeneratorSettings, $args);
		$exporterType = Billrun_Util::getIn($exportGeneratorSettings, 'exporter.type', '');
		$class = 'Billrun_Exporter_' . ucfirst($exporterType);
		if (!@class_exists($class, true)) {
			Billrun_Factory::log("Can't find class: " . $class, Zend_Log::EMERG);
			return false;
		}
		self::$instance[$stamp] = new $class($params);
		return self::$instance[$stamp];
	}
	
	/**
	 * get stamp for the current run of the exporter
	 */
	protected function getExportStamp() {
		if (is_null($this->exportStamp)) {
			$this->exportStamp = uniqid();
		}
		return $this->exportStamp;
	}
	
	/**
	 * gets collection to load data from DB
	 * 
	 * @return string
	 */
	protected function getCollection() {
		if (is_null($this->collection)) {
			$querySettings = $this->config['queries'][0]; // TODO: currenly, supporting 1 query might support more in the future
			$collectionName = $querySettings['collection'];
			$this->collection = Billrun_Factory::db()->{"{$collectionName}Collection"}();
		}
		return $this->collection;
	}
	
	/**
	 * get query to load data from the DB
	 */
	protected function getQuery() {
		$querySettings = $this->config['queries'][0]; // TODO: currenly, supporting 1 query might support more in the future
		$query = json_decode($querySettings['query'], JSON_OBJECT_AS_ARRAY);
		if (isset($querySettings['time_range'])) {
			$timeRange = $querySettings['time_range'];
			$endTime = $this->exportTime;
			$startTime = strtotime($timeRange, $endTime);
			$query['urt'] = array(
				'$gte' => new MongoDate($startTime),
				'$lte' => new MongoDate($endTime),
			);
		}
		return $query;
	}

	/**
	 * general function to handle the export
	 *
	 * @return array list of lines exported
	 */
	abstract function handleExport();
	
	/**
	 * general function to handle the export
	 *
	 * @return array list of lines exported
	 */
	function export() {
		$this->beforeExport();
		$this->prepareDataToExport();
		$exportedData = $this->handleExport();
		$this->afterExport();
		return $exportedData;
	}
	
	/**
	 * gets record type according to configuration mapping
	 * 
	 * @return string
	 */
	protected function getRecordType($row) {
		foreach (Billrun_Util::getIn($this->config, 'record_type_mapping', array()) as $recordTypeMapping) {
			foreach ($recordTypeMapping['conditions'] as $condition) {
				if (!Billrun_Util::isConditionMet($row, $condition)) {
					continue 2;
				}
			}
			return $recordTypeMapping['record_type'];
		}
		return '';
	}
	
	/**
	 * translate row to the format it should be exported
	 * 
	 * @param array $row
	 * @return array
	 */
	protected function getRecordData($row) {
		$recordType = $this->getRecordType($row);
		$fieldsMapping = Billrun_Util::getIn($this->config, array('fields_mapping', $recordType));
		return $this->mapFields($fieldsMapping, $row);
	}
	
	/**
	 * checks if there is a header
	 * 
	 * @return boolean
	 */
	protected function hasHeader() {
		$headerMapping = Billrun_Util::getIn($this->config, 'header_mapping');
		return !empty($headerMapping);
	}

	/**
	 * checks if there is a footer
	 * 
	 * @return boolean
	 */
	protected function hasFooter() {
		$footerMapping = Billrun_Util::getIn($this->config, 'footer_mapping');
		return !empty($footerMapping);
	}
	
	/**
	 * loads the header data (first line in file)
	 * 
	 * @return array
	 */
	protected function loadHeader() {
		if (!$this->hasHeader()) {
			return false;
		}
		$headerMapping = Billrun_Util::getIn($this->config, 'header_mapping');
		$this->headerToExport = $this->mapFields($headerMapping);
	}
	
	/**
	 * loads the footer data (last line in file)
	 * 
	 * @return array
	 */
	protected function loadFooter() {
		if (!$this->hasFooter()) {
			return false;
		}
		$footerMapping = Billrun_Util::getIn($this->config, 'footer_mapping');
		$this->footerToExport = $this->mapFields($footerMapping);
	}
	
	/**
	 * method to log the export process
	 */
	protected function logDB($stamp, $data) {
		if (empty($stamp)) {
			Billrun_Factory::log()->log("Billrun_Exporter::logDB - got export with empty stamp. data: " . print_R($data, 1), Zend_Log::NOTICE);
			return false;
		}
		$log = Billrun_Factory::db()->logCollection();
		Billrun_Factory::dispatcher()->trigger('beforeLogExport', array(&$data, $stamp, $this));
		
		$query = array(
			'stamp' =>  $stamp,
			'source' => 'export',
			'type' => static::$type,
		);

		$update = array(
			'$set' => $data,
		);

		$result = $this->logCollection->update($query, $update, array('w' => 1));
		$success = $result == true || ($result['n'] == 1 && $result['ok'] == 1);

		if (!$success) {
			Billrun_Factory::log()->log("Billrun_Exporter::logDB - Failed when trying to update an export log record with stamp of : {$stamp}. data: " . print_R($data, 1), Zend_Log::NOTICE);
			return false;
		}
		
		return true;
	}
	
	/**
	 * creates basic log in DB
	 * 
	 * @param string $stamp
	 * @return type
	 */
	protected function createLogDB($stamp, $data = array()) {		
		$basicLogData = array(
			'stamp' =>  $stamp,
			'source' => 'export',
			'type' => static::$type,
			'export_hostname' => Billrun_Util::getHostName(),
			'export_start_time' => new MongoDate(),
		);
		$logData = array_merge($basicLogData, $data);

		$result = $this->logCollection->insert($logData);
		$success = $result == true || ($result['n'] == 1 && $result['ok'] == 1);

		if (!$success) {
			Billrun_Factory::log()->log("Billrun_Exporter::createLogDB - Failed when trying to insert an export log record" . print_r($logData, 1) . " with stamp of : {$stamp}", Zend_Log::NOTICE);
			return false;
		}
		
		return true;
	}
	
	/**
	 * get rows to be exported
	 * 
	 * @return array
	 */
	protected function loadRows() {
		$collection = $this->getCollection();
		$rows = $collection->query($this->query)->cursor();
		foreach ($rows as $row) {
			$rawRow = $row->getRawData();
			$this->rawRows[] = $rawRow;
			$this->rowsToExport[] = $this->getRecordData($rawRow);
		}
	}
	
	/**
	 * mark the lines which are about to be exported
	 */
	function beforeExport() {
		$this->query['export_start'] = array(
			'$exists' => false,
		);
		$this->query['export_stamp'] = array(
			'$exists' => false,
		);
		$update = array(
			'$set' => array(
				'export_start' => new MongoDate(),
				'export_stamp' => $this->exportStamp,
			),
		);
		$options = array(
			'multiple' => true,
		);
		
		$collection = $this->getCollection();
		$collection->update($this->query, $update, $options);
		unset($this->query['export_start']);
		$this->query['export_stamp'] = $this->exportStamp;
		$this->createLogDB($this->getLogStamp());
	}
	
		/**
	 * gets data to log after export is done
	 * 
	 * @return array
	 */
	protected function getLogData() {
		return array(
			'sequence_num' => $this->getSequenceNumber(),
			'exported_time' => new MongoDate(),
		);
	}
	
	/**
	 * gets stamp in use for the log
	 * 
	 * @return type
	 */
	protected function getLogStamp() {
		if (empty($this->logStamp)) {
			$stampArr = array(
				'export_stamp' => $this->exportStamp,
				'sequence_num' => $this->getSequenceNumber(),
			);
			$this->logStamp = Billrun_Util::generateArrayStamp($stampArr);
		}
		return $this->logStamp;
	}
	
	/**
	 * mark the lines as exported
	 */
	function afterExport() {
		$stamps = array();
		foreach ($this->rawRows as $row) {
			$stamps[] = $row['stamp'];
		}
		$query = array(
			'stamp' => array(
				'$in' => $stamps,
			),
		);
		$update = array(
			'$set' => array(
				'exported' => new MongoDate(),
			),
		);
		$options = array(
			'multiple' => true,
		);
		
		$collection = $this->getCollection();
		$collection->update($query, $update, $options);
		$this->logDB($this->getLogStamp(), $this->getLogData());
	}

	/**
	 * prepare the data to be exported
	 * 
	 * @return array
	 */
	protected function prepareDataToExport() {
		$this->loadRows();
		$this->loadHeader();
		$this->loadFooter();
	}
	
	public function mapFields($fieldsMapping, $row = array()) {
		$data = array();
		foreach ($fieldsMapping as $fieldMapping) {
			$val = '';
			$fieldName = $fieldMapping['field_name'];
			$mapping = $fieldMapping['mapping'];
			if (!is_array($mapping)) {
				$val = Billrun_Util::getIn($row, $mapping, $mapping);
			} else if (isset($mapping['field'])) {
				$val = Billrun_Util::getIn($row, $mapping['field'], '');
			} else if(isset ($mapping['hard_coded'])) {
				$val = $mapping['hard_coded'];
			} else if (isset ($mapping['conditions'])) {
				$val = isset($mapping['default']) ? $mapping['default'] : '';
				foreach ($mapping['conditions'] as $condition) {
					if (Billrun_Util::isConditionMet($row, $condition)) {
						$val = $condition['result'];
						break;
					}
				}
			} else if (isset($mapping['func'])) {
				$funcName = $mapping['func']['func_name'];
				if (!method_exists($this, $funcName)) {
					Billrun_Log::getInstance()->log('Bulk exporter: mapping pre-defined function "' . $funcName . '" does not exist in class "' . $className . '"', Zend_log::WARN);
				} else {
					$val = $this->{$funcName}($row, $mapping);
				}
			} else {
				Billrun_Log::getInstance()->log('Bulk exporter: invalid mapping: ' . print_R($fieldMapping, 1), Zend_log::WARN);
			}
			
			if (!is_null($val)) {
				$val = self::formatMappingValue($val, $mapping);
				Billrun_Util::setIn($data, explode('>', $fieldName), $val);
			}
		}
		
		return $data;
	}
	
	protected function formatMappingValue($value, $mapping) {
		if (isset($mapping['padding'])) {
			$padding = Billrun_Util::getIn($mapping, 'padding.character', ' ');
			$length = Billrun_Util::getIn($mapping, 'padding.length', strlen($value));
			$padDirection = strtolower(Billrun_Util::getIn($mapping, 'padding.direction', 'left')) == 'right' ? STR_PAD_RIGHT : STR_PAD_LEFT;
			return str_pad($value, $length, $padding, $padDirection);
		}
		if (isset($mapping['format']['regex'])) {
			return preg_replace($mapping['format']['regex'], '', $value);
		}
		if (isset($mapping['format']['date'])) {
			return $this->formatDate($value, $mapping);
		}
		return $value;
	}
	
	protected function formatDate($date, $mapping) {
		if ($date instanceof MongoDate) {
			$date = $date->sec;
		} else if (is_string($date)) {
			$date = strtotime($date);
		}
		$dateFormat = Billrun_Util::getIn($mapping, 'format.date', 'YmdHis');
		return date($dateFormat, $date);
	}
	
	
	/** pre-defined functions start **/
	
	protected function callCustomFunction($row = array(), $mapping = array()) {
		$customFuncName = $mapping['func']['custom_func_name'];
		$ret = '';
		Billrun_Factory::dispatcher()->trigger($customFuncName, array($row, $mapping, &$ret));
		return $ret;
	}
	
	/**
	 * gets current sequence number for the file
	 * 
	 * @return string - number in the range of 00001-99999
	 */
	protected function getSequenceNumber() {
		if (is_null($this->sequenceNum)) {
			$query = array(
				'source' => 'export',
				'type' => static::$type,
			);
			$sort = array(
				'export_start_time' => -1,
			);
			$lastSeq = $this->logCollection->query($query)->cursor()->sort($sort)->limit(1)->current()->get('sequence_num');
			if (is_null($lastSeq)) {
				$nextSeq = self::SEQUENCE_NUM_INIT;
			} else {
				$nextSeq = $lastSeq + 1;
			}
			$this->sequenceNum = sprintf('%05d', $nextSeq % 100000);
		}
		return $this->sequenceNum;
	}
	
	protected function getTimeStamp($row = array(), $mapping = array()) {
		$format = Billrun_Util::getIn($mapping, 'func.date_format', Billrun_Util::getIn($mapping, 'format', 'YmdHis'));
		return date($format, $this->exportTime);
	}
	
	protected function getNumberOfRecords($row = array(), $mapping = array()) {
		$numberOfRecords = count($this->rowsToExport);
		if ($this->hasHeader()) {
			$numberOfRecords++;
		}
		if ($this->hasFooter()) {
			$numberOfRecords++;
		}
		return $this->formatMappingValue($numberOfRecords, $mapping);
	}
	
	/** pre-defined functions end **/
}

<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing Files receiver class
 *
 * @package  Billing
 * @since    1.0
 */
abstract class Billrun_Receiver_Base_LocalFiles extends Billrun_Receiver {

	/**
	 * Type of object
	 *
	 * @var string
	 */
	static protected $type = 'localfiles';

	/**
	 * the source directory to get the files from.
	 * @var type 
	 */
	protected $srcPath = null;

	public function __construct($options) {
		parent::__construct($options);

		if (isset($options['workspace'])) {
			$this->workspace = $options['workspace'];
		}

		if (isset($options['path'])) {
			$this->srcPath = $options['path'];
		}
	}

	/**
	 * General function to receive
	 *
	 * @return array list of files received
	 */
	public function receive() {

		$this->dispatcher->trigger('beforeLocalFilesReceive', array($this));

		$type = static::$type;
		if (!file_exists($this->srcPath)) {
			$this->log->log("NOTICE : SKIPPING $type !!! directory " . $this->srcPath . " not found!!", Zend_Log::NOTICE);
			return FALSE;
		}
		$files = scandir($this->srcPath);
		$ret = array();
		foreach ($files as $file) {
			$path = $this->srcPath . DIRECTORY_SEPARATOR . $file;
			if (!$this->isFileValid($file, $path) || $this->isFileReceived($file, $type) || is_dir($path)) {
				continue;
			}
			$this->log->log("Billrun_Receiver_Base_LocalFiles::receive - Handaled file {$file}", Zend_Log::DEBUG);
			$path = $this->handleFile($path, $file);
			if (!$path) {
				$this->log->log("NOTICE : Couldn't relocate file from  $path.", Zend_Log::NOTICE);
				continue;
			}
			$this->logDB($path);
			$ret[] = $path;
		}

		$this->dispatcher->trigger('afterLocalFilesReceived', array($this, $ret));

		return $ret;
	}

	/**
	 * Move the file to the workspace.
	 * 
	 * 
	 * @return string the new path
	 */
	protected function handleFile($srcPath, $filename) {
		$this->dispatcher->trigger('handlingLocalFilesReceive', array($this, &$srcPath, $filename));
		return $srcPath;
	}

	/**
	 * Get the directory that the files should be stored in.
	 * @return the Base dirctory that the received files should be transfered to.
	 */
	protected function getDestBasePath() {
		return $this->workspace . DIRECTORY_SEPARATOR . static::$type;
	}

}

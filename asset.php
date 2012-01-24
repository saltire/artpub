<?php

// media asset context for article renderer
// last modified feb 10 2011

class Asset extends Context {

	protected $file;

	public function __construct($file, $tree, $webroot, $index = 0, $total = 1) {
		$this->file = $file;
		$this->tree = $tree;
		$this->webroot = $webroot;

		$pathinfo = pathinfo($this->file);
		$this->path = $pathinfo['dirname']; // for asset render
		
		preg_match('`^((\d+)\.)?([-\w\s\.]*)$`', $pathinfo['filename'], $matches);
		$this->vars = array(
			'uri' => "{$this->webroot}/content/" . ltrim($file, $this->tree->get_content_root()),
			'index' => $index + 1,
			'filename' => basename($file),
			'extension' => $pathinfo['extension'],
			'file_index' => $matches[2],
			'name' => $matches[3],
			'is_first' => $index == 0 ? 1 : 0,
			'is_last' => $index + 1 == $total ? 1 : 0
		);
	}

	public function get_collection($cname) {
		// future expansion
		return array();
	}

}
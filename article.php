<?php

// article context for renderer
// last modified nov 16 2011

class Article extends Context {

	protected $route;
	protected $info;
	protected $assets = array();
	protected $article_collections = array();
	protected $asset_collections = array();

	public function __construct($route, $tree, $webroot) {
		$this->tree = $tree;
		$this->webroot = $webroot;

		// remove leading slash, add trailing slash
		$this->route = preg_replace('`^/?(.+?)/?$`', '$1/', $route);
		
		$this->info = $this->tree->get_route_info($this->route);
		$this->path = $this->info['path']; // used for asset rendering
		
		// vars
		$vars = $this->generate_vars();
		$txtvars = $this->tree->get_txt_vars($this->route);
		$this->vars = $txtvars ? array_merge($vars, $txtvars) : $vars;

		// collections
		$this->article_collections = $this->generate_article_collections();
		$this->asset_collections = $this->generate_asset_collections();
	}

	public function get_template() {
		return strtolower($this->info['template']);
	}

	public function get_collection($cname) {
		$collection = array();

		if (array_key_exists($cname, $this->article_collections)) {
			foreach ($this->article_collections[$cname] as $route) {
				$collection[] = new Article($route, $this->tree, $this->webroot);
			}
			
		} elseif (array_key_exists($cname, $this->asset_collections)) {
			$count = count($this->asset_collections[$cname]);
			foreach ($this->asset_collections[$cname] as $index => $file) {
				$collection[] = new Asset($file, $this->tree, $this->webroot, $index, $count);
			}
		}

		return $collection;
	}

	protected function generate_vars() {
		// predefined @variables - a collection of strings associated with a route
		$uri = "{$this->webroot}/$this->route";
		$children = $this->info['children'];
		$is_first = $this->info['index'] == 1 ? 1 : 0;
		$is_last = $this->info['index'] == count($this->info['siblings']) ? 1 : 0;
		return array(
			'root' => $this->webroot,
			'page_name' => ucwords((str_replace('-', ' ', $this->info['slug']))),
			'uri' => $uri,
			'route' => $this->route,
			'index' => $this->info['index'],
			'folder_index' => $this->info['folder_index'],
			'slug' => $this->info['slug'],
			'is_current' => $uri == "http://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}" ? 1 : 0,
			'is_first' => $is_first,
			'is_last' => $is_last,
			'parent' => $this->route ? preg_replace('`(.*/)?[^/]*/$`', '$1', $this->route) : null,
			'prev_sibling' => $this->info['index'] && !$is_first ? $this->info['siblings'][$this->info['index'] - 1] : null,
			'next_sibling' => $this->info['index'] && !$is_last ? $this->info['siblings'][$this->info['index'] + 1] : null,
			'first_sibling' => $this->info['siblings'] ? $this->info['siblings'][1] : null,
			'last_sibling' => $this->info['siblings'] ? $this->info['siblings'][count($this->info['siblings'])] : null,
			'first_child' => $children ? $children[1] : null,
			'last_child' => $children ? end($children) : null
		);
	}

	protected function generate_article_collections() {
		$root = $this->tree->get_route_info('');
		return array(
			'root' => $root['children'],
			'children' => $this->info['children'],
			'siblings' => $this->info['siblings']
		);
	}

	protected function generate_asset_collections() {
		$collections = array();

		// file $collections by extension
		foreach ($this->get_files($this->tree->get_content_root() . "/{$this->info['path']}/*") as $file) {
			$pathinfo = pathinfo($file);
			$collections[$pathinfo['extension']][] = $file;
		}

		// file $collections by category (merged groups of above)
		$cats = array(
			'image' => array('jpg', 'jpeg', 'gif', 'png'),
			'video' => array('m4v', 'mov', 'mp4', 'swf'),
			'audio' => array('mp3', 'aac', 'ogg', 'm4a', 'wav'),
			'html' => array('html', 'htm')
		);
		foreach ($cats as $cat => $exts) {
			$cat_files = array();
			foreach ($exts as $ext) {
				if (in_array($ext, $collections) && is_array($collections[$ext])) {
					$cat_files += $collections[$ext];
				}
			}
			if ($cat_files) {
				$collections[$cat] = $cat_files;
			}
		}

		// file $collections by _folder
		$_dirs = glob($this->tree->get_content_root() . "/{$this->info['path']}/_*", GLOB_ONLYDIR);
		if (is_array($_dirs)) {
			$asset_dirs = array();
			foreach ($_dirs as $_dir) {
				$collections[ltrim($_dir, '_')] = $this->get_files("$_dir/*.*");
			}
		}

		return $collections;
	}

	protected function get_files($pattern) {
		$files = array();
		$dirlist = glob($pattern);
		if (is_array($dirlist)) {
			natsort($dirlist);
			foreach ($dirlist as $file) {
				if (!is_dir($file)) {
					$files[] = $file;
				}
			}
		}
		return $files;
	}
}
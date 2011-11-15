<?php

class Article extends Context {

	protected $tree;
	protected $route;
	protected $info;
	protected $template;
	protected $assets = array();
	protected $article_collections = array();
	protected $asset_collections = array();

	public function __construct($route) {
		$this->tree = new FileTree(CONTENT_ROOT);

		// remove leading slash, add trailing slash
		$this->route = preg_replace('`^/?(.+?)/?$`', '$1/', $route);

		$this->info = $this->tree->getRouteInfo($this->route);
		$this->path = $this->info['path']; // used for asset rendering

		$template = strtolower($this->info['template']);
		if (!$template) {
			throw new Exception("No article found.");
		}

		// template
		$files = glob(TEMPLATE_ROOT . "/$template.*");
		if (!$files) {
			throw new Exception("The template '$template' does not exist.");
		}
		$this->template = $files[0];

		// vars
		$vars = $this->generateVars();
		$txtvars = $this->tree->getTxtVars($this->route);
		$this->vars = $txtvars ? array_merge($vars, $txtvars) : $vars;

		// collections
		$this->article_collections = $this->generateArticleCollections();
		$this->asset_collections = $this->generateAssetCollections();
	}

	public function getTemplatePath() {
		return $this->template;
	}

	public function getCollection($cname) {
		$collection = array();

		if (array_key_exists($cname, $this->article_collections)) {
			foreach ($this->article_collections[$cname] as $route) {
				$collection[] = new Article($route);
			}
			
		} elseif (array_key_exists($cname, $this->asset_collections)) {
			$count = count($this->asset_collections[$cname]);
			foreach ($this->asset_collections[$cname] as $index => $file) {
				$collection[] = new Asset($file, $index, $count);
			}
		}

		return $collection;
	}

	protected function generateVars() {
		// predefined @variables - a collection of strings associated with a route
		$uri = WEB_ROOT . "/$this->route";
		$children = $this->info['children'];
		$is_first = $this->info['index'] == 1 ? 1 : 0;
		$is_last = $this->info['index'] == count($this->info['siblings']) ? 1 : 0;
		return array(
			'root' => WEB_ROOT,
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
			'first_sibling' => $this->info['siblings'][1],
			'last_sibling' => $this->info['siblings'][count($this->info['siblings'])],
			'first_child' => $children ? $children[1] : null,
			'last_child' => end($children)
		);
	}

	protected function generateArticleCollections() {
		$root = $this->tree->getRouteInfo('');
		return array(
			'root' => $root['children'],
			'children' => $this->info['children'],
			'siblings' => $this->info['siblings']
		);
	}

	protected function generateAssetCollections() {
		$collections = array();

		// file $collections by extension
		foreach ($this->getFiles(CONTENT_ROOT . "/{$this->info['path']}/*") as $file) {
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
		$_dirs = glob(CONTENT_ROOT . "/{$this->info['path']}/_*", GLOB_ONLYDIR);
		if (is_array($_dirs)) {
			$asset_dirs = array();
			foreach ($_dirs as $_dir) {
				$collections[ltrim($_dir, '_')] = $this->getFiles("$_dir/*.*");
			}
		}

		return $collections;
	}

	protected function getFiles($pattern) {
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
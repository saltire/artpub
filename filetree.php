<?php

// file tree object for article renderer
// last modified nov 16 2011

require_once('includes/markdown.php');

class FileTree {

	protected $routes = array();

	public function __construct($content) {
		$this->root = $content;
		$txts = glob("{$this->root}/*.txt");
		$this->routes[''] = array(
			'template' => $txts ? basename($txts[0], '.txt') : null,
			'path' => '',
			'index' => null,
			'folder_index' => '',
			'slug' => '',
			'children' => $this->addRouteChildren($this->root),
			'siblings' => array()
		);
	}

	protected function addRouteChildren($path, $parent_route = '') {
		// return an array of child routes
		// side-effect: add all descendents' route data to $this->routes
		$children = array();
		$ichildren = array(); // child articles with numerical indices
		$dirs = glob("$path/*", GLOB_ONLYDIR);
		if (is_array($dirs)) {
			$i = 1;
			natsort($dirs);
			foreach ($dirs as $dir) {
				$txts = glob("$dir/*.txt");
				// only include the child if it has a txt file
				if (is_array($txts)) {
					preg_match('`^(_)?((\d+)\.)?(_)?([-\w]*)$`', basename($dir), $matches);
					if (!$matches[1] && !$matches[4]) {
						$folder_index = $matches[3];
						$slug = $matches[5];
						$child_route = "{$parent_route}{$slug}/";

						// only include in ichildren array if indexed
						// ichildren array will start at 1, not 0
						$children[$child_route] = array(
							'template' => $txts ? basename($txts[0], '.txt') : null,
							'path' => ltrim(str_replace($this->root, '', $dir), '/'),
							'index' => $folder_index ? $i : null,
							'folder_index' => $folder_index,
							'slug' => $slug,
							'children' => $this->addRouteChildren($dir, $child_route)
						);
						if ($folder_index) {
							$ichildren[$i] = $child_route;
							$i++;
						}
					}
				}
			}
		}
		// now that all children have been processed, we can add
		// indexed siblings to each child, and add each child to routes
		foreach ($children as $child_route => $child) {
			$child['siblings'] = $ichildren;
			$this->routes[$child_route] = $child;
		}

		return $ichildren;
	}
	
	public function getContentRoot() {
		return $this->root;
	}

	public function getRouteInfo($route) {
		return array_key_exists($route, $this->routes) ? $this->routes[$route] : null;
	}

	public function getRouteTree($route) {
		if (!array_key_exists($route, $this->routes)) {
			return null;
		}

		$tree = array();
		foreach ($this->routes[$route]['children'] as $child_route) {
			$tree[$child_route] = $this->getRouteTree($child_route);
		}
		return $tree;
	}

	public function getRouteTreeFlat($route) {

	}

	public function getTxtVars($route) {
		if (!array_key_exists($route, $this->routes)) {
			return null;
		}

		$vars = array();
		if ($this->routes[$route]['template']) {
			$txtfile = file_get_contents("{$this->root}/{$this->routes[$route]['path']}/{$this->routes[$route]['template']}.txt");
			$txtfile = preg_replace('`\r\n?`', "\n", $txtfile);
			// find each named variable in the txt file
			// vars are separated by a line consisting of a single dash
			preg_match_all('`(?<=\n)([-\w]+?):\s*?([\S\s]*?)(?=\n-\n)`', "\n$txtfile\n-\n", $matches, PREG_SET_ORDER);
			foreach ($matches as $match) {
				$multiline = (strpos($match[0], "\n") !== false);
				$key = str_replace('-', '_', strtolower($match[1]));
				$value = htmlentities(trim($match[2]), ENT_COMPAT, 'UTF-8');
				$vars[$key] = $multiline ? Markdown($value) : $value;
			}
		}
		return $vars;
	}

}
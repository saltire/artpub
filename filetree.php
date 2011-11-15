<?php

class FileTree {

	protected $routes = array();

	public function __construct() {
		$txts = glob(CONTENT_ROOT . "/*.txt");
		$this->routes[''] = array(
			'template' => basename($txts[0], '.txt'),
			'path' => '',
			'children' => $this->addRouteChildren(CONTENT_ROOT),
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
			$i = 0;
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
						if ($folder_index) {
							$i++;
							$ichildren[$i] = $child_route;
						}
						$children[$child_route] = array(
							'template' => basename($txts[0], '.txt'),
							'path' => ltrim(str_replace(CONTENT_ROOT, '', $dir), '/'),
							'index' => $folder_index ? $i : null,
							'folder_index' => $folder_index,
							'slug' => $slug,
							'children' => $this->addRouteChildren($dir, $child_route)
						);
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
			$txtfile = file_get_contents(CONTENT_ROOT . "/{$this->routes[$route]['path']}/{$this->routes[$route]['template']}.txt");
			$txtfile = preg_replace('`\r\n?`', "\n", $txtfile);
			preg_match_all('`(?<=\n)([-\w]+?):\s*?([\S\s]*?)(?=\n-\n)`', "\n$txtfile\n-\n", $matches, PREG_SET_ORDER);
			foreach ($matches as $match) {
				$multiline = (strpos($match[0], "\n") !== false);
				$key = str_replace('-', '_', strtolower($match[1]));
				$value = trim($match[2]);
				$vars[$key] = $multiline ? Markdown($value) : $value;
			}
		}
		return $vars;
	}

}
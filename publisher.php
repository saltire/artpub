<?php

// article publisher
// last modified nov 16 2011

require_once('filetree.php');
require_once('context.php');
require_once('article.php');
require_once('asset.php');

class Publisher {
	protected $tree;
	protected $templatedir;

	public function __construct($content, $templates) {
		$this->tree = new FileTree($content);
		$this->templatedir = $templates;
	}
	
	public function publishArticle($webroot, $route) {
		$article = new Article($route, $this->tree, $webroot);

		// set template path
		$template = $article->getTemplate();
		if (!$template) {
			throw new Exception("No article found.");
		}
		$files = glob("{$this->templatedir}/$template.*");
		if (!$files) {
			throw new Exception("The template '$template' does not exist.");
		}
		$template_path = $files[0];
		
		// set content type
		$mimetypes = array(
			'html' => 'text/html',
			'php' => 'text/html',
			'rss' => 'application/rss+xml'
		// etc.
		);
		$pathinfo = pathinfo($template_path);
		if (array_key_exists($pathinfo['extension'], $mimetypes)) {
			header("Content-type: {$mimetypes[$pathinfo['extension']]}; charset=utf-8");
		}

		// render that shit
		ob_start();
		include $template_path;
		$output = ob_get_clean();

		return $this->parse($output, $article);
	}

	// context can be either an article or an asset
	protected function parse($output, $context) {
		// substitute ':element' for template in elements subdir
		while (preg_match('`[\b\s>]:(\w+)\b`', $output, $matches)) {
			$template = $matches[1];

			// include the template file
			ob_start();
			$files = glob("{$this->templatedir}/elements/$template.*");
			if (!is_array($files)) {
				throw new Exception("The template '$template' does not exist.");
			}
			include $files[0];
			$element = ob_get_clean();

			// sub it into the output
			$output = preg_replace('`[\b\s>]:' . $template . '\b`', $element, $output);
		}

		// parse next 'get', 'foreach', or 'if' block, whichever comes first
		while (preg_match(
				'`^[\S\s]*?\b(?:(?<get>get\s+"(?:@[\w\d]+|[-/\w\d]+)")|(?<foreach>foreach\s+\$[-\w\d]+)|(?<if>if\s+!?@[\w\d]+)):`',
				$output, $matches)) {

			if ($matches['get']) {
				// substitute 'get "route": ... endget' variables for variables in route
				preg_match(
						'`^([\S\s]*?)\bget\s+"(@[\w\d]+|[-/\w\d]+)":([\S\s]+?)\bendget\b([\S\s]*)$`',
						$output, $matches);
				list(, $before, $getroute, $block, $after) = $matches;
				list($block, $after) = $this->expandBlock('`\bget\s+"[-/@\w\d]+":`', 'endget', $block, $after);

				// allow variables in place of route
				$getroute = preg_replace_callback(
						'`@([\w\d]+)`',
						function($matches) use ($context) {
							return $context->getVar($matches[1]);
						},
						$getroute);
				// remove leading slash, add trailing slash
				$getroute = preg_replace('`^/?([^/]+?)/?$`', '$1/', $getroute);

				// parse block using variables from the new route
				$output = $before;
				$newarticle = new Article($getroute, $this->tree, $context->getWebRoot());
				$output .= $this->parse($block, $newarticle);
				$output .= $after;

			} elseif ($matches['foreach']) {
				// substitute 'foreach $collection: ... endforeach' for loop of contents with each item in collection
				preg_match(
						'`^([\S\s]*?)\bforeach\s+\$([-\w\d]+):([\S\s]+?)\bendforeach\b([\S\s]*)$`',
						$output, $matches);
				list(, $before, $cname, $block, $after) = $matches;
				list($block, $after) = $this->expandBlock('`\bforeach\s+\$[-\w\d]+:`', 'endforeach', $block, $after);

				// check for modifiers
				$reverse = 0;
				if (substr($cname, -8) == "-reverse") {
					$reverse = 1;
					$cname = substr($cname, 0, -8);
				}

				// parse loop once for each article/asset in the collection
				$output = $before;

				$collection = $context->getCollection($cname);
				if ($collection) {
					foreach ($reverse ? array_reverse($collection) : $collection as $newcontext) {
						$output .= $this->parse($block, $newcontext);
					}
				}
				$output .= $after;

			} elseif ($matches['if']) {
				// substitute 'if (!)@variable: ... endif' for contents, if condition is true
				preg_match(
						'`^([\S\s]*?)\bif\s+(!)?([\$@])([\w\d]+)(=([\'"])([^\\6]+)\\6)?:([\S\s]+?)\bendif\b([\S\s]*)$`',
						$output, $matches);
				list(, $before, $neg, $type, $var, $is_comp, , $value, $block, $after) = $matches;
				list($block, $after) = $this->expandBlock('`\bif\s+!?[\$@][\w\d]+:`', 'endif', $block, $after);

				// evaluate variable or collection to true or false
				$result = 0;
				if ($type == '$') {
					$result = ($context->getArticleCollections($var) || $context->getAssetCollections($var)) ? 1 : 0;
				} elseif ($type == '@') {
					if ($is_comp) {
						$result = $context->getVar($var) == $value;
					} else {
						$result = (bool)$context->getVar($var);
					}
				}

				// include block if result is true (or ! is present and result is false)
				$output = $before . (($result xor $neg) ? $block : '') . $after;
			}
		}

		// the following are evaluated only after dealing with all nested blocks,
		// so as to be rendered in the context of the most immediate containing block

		// substitute '@variable' with context's variable value
		$output = preg_replace_callback(
				'`@([\w\d]+)`',
				function($matches) use ($context) {
					return $context->getVar($matches[1]);
				},
				$output);
				
		// substitute '%[service]:asset(attr1: value, attr2: value)' with asset html
		// service and attribute list are optional
		// attribute values must escape commas or right parens
		// p.s. that's a fairly big-ass regex
		$output = preg_replace_callback(
				'`%(?:(\w+):)?([\w\d\.]+|"[^"]+")(?:\((\s*\w+\s*:\s*(?:[^,\)]|\\[,\)])+(?:,\s*\w+\s*:\s*(?:[^,\)]|\\[,\)])+)*\s*)\))?`',
				function($matches) use ($context) {
					list(, $service, $name, $attrlist) = array_pad($matches, 4, '');
					return $context->renderAsset($service, $name, $attrlist);
				},
				$output);
				
		// substitute '$function(arg1,arg2)' for output of '$this->f_function(arg1, arg2)' IF function exists
		preg_match_all(
				'`^([\S\s]*?)#(\w+)\(([-/\w\d,]+)\)([\S\s]*)$`',
				$output, $matches, PREG_SET_ORDER);
		foreach ($matches as $matchset) {
			list(, $before, $function, $args, $after) = $matchset;
			if (function_exists("f_$function")) {
				$output = $before . call_user_func_array(array($this, "f_$function"), explode(',', $args)) . $after;
			}
		}

		return $output;
	}

	// expand block to close all nested structures
	protected function expandBlock($pattern, $end, $block, $after) {
		if (preg_match_all($pattern, $block, $start_matches)) {
			for ($i = 0; $i < count($start_matches[0]); $i++) {
				if (preg_match('`([\S\s]*?)\b' . $end . '\b([\S\s]*)`', $after, $end_matches)) {
					$block .= "$end{$end_matches[1]}";
					$after = $end_matches[2];
				} else {
					throw new Exception("Missing '$end' in template.");
				}
			}
		}
		return array($block, $after);
	}
}
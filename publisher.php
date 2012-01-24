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

	public function __construct($contentdir, $templatedir) {
		$this->tree = new FileTree($contentdir);
		$this->templates = $this->read_template_dir(realpath($templatedir));
	}
	
	protected function read_template_dir($path) {
		// recursively read all files in a dir
		// return an array of paths indexed by filename, w/o extension
		$templates = array();
		$files = glob("$path/*");
		foreach ($files as $file) {
			if (!is_dir($file)) {
				$pathinfo = pathinfo($file);
				if (!array_key_exists($pathinfo['filename'], $templates)) {
					$templates[$pathinfo['filename']] = $file;
				}
			} else {
				$templates += $this->read_template_dir($file);
			}
		}
		return $templates;
	}
	
	public function publish_article($webroot, $route) {
		$article = new Article($route, $this->tree, $webroot);

		// set template path
		$template = $article->get_template();
		if (!$template) {
			throw new Exception("No article found.");
		} elseif (!array_key_exists($template, $this->templates)) {
			throw new Exception("The template '$template' does not exist.");
		}
		
		// set content type
		$mimetypes = array(
			'html' => 'text/html',
			'php' => 'text/html',
			'rss' => 'application/rss+xml'
		// etc.
		);
		$pathinfo = pathinfo($this->templates[$template]);
		if (array_key_exists($pathinfo['extension'], $mimetypes)) {
			header("Content-type: {$mimetypes[$pathinfo['extension']]}; charset=utf-8");
		}

		// render that shit
		ob_start();
		include $this->templates[$template];
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
			if (!array_key_exists($template, $this->templates)) {
				throw new Exception("The template '$template' does not exist.");
			}
			include $this->templates[$template];
			$element = ob_get_clean();

			// sub it into the output
			$output = preg_replace(sprintf('`[\b\s>]:%s\b`', $template), $element, $output);
		}

		// parse next 'get', 'foreach', or 'if' block, whichever comes first
		while (preg_match(
				'`\b(?:
					(?<get>get\s+"(?:@[\w\d]+|[-/\w\d]+)")|
					(?<foreach>foreach\s+\$[-\w\d]+)|
					(?<if>if\s+!?@[\w\d]+)
				)`x',
				$output, $blockmatches)) {

			// substitute 'get "route": ... endget' variables for variables in route
			if ($blockmatches['get']) {
				preg_match(
						'`^([\S\s]*?)\b
							get\s+"(@[\w\d]+|[-/\w\d]+)":
								([\S\s]+?)\b
							endget\b
						([\S\s]*)$`x',
						$output, $matches);
				list(, $before, $getroute, $block, $after) = $matches;
				list($block, $after) = $this->expand_block('`\bget\s+"[-/@\w\d]+":`', 'endget', $block, $after);

				// allow variables in place of route
				$getroute = preg_replace_callback(
						'`@([\w\d]+)`',
						function($matches) use ($context) {
							return $context->get_var($matches[1]);
						},
						$getroute);
				// remove leading slash, add trailing slash
				$getroute = preg_replace('`^/?([^/]+?)/?$`', '$1/', $getroute);

				// parse block using variables from the new route
				$newarticle = new Article($getroute, $this->tree, $context->get_web_root());
				$newblock = $this->parse($block, $newarticle);

			// substitute 'foreach $collection: ... endforeach' for loop of contents with each item in collection
			} elseif ($blockmatches['foreach']) {
				preg_match(
						'`^([\S\s]*?)\b
							foreach\s+\$([-\w\d]+):
								([\S\s]+?)\b
							endforeach\b
						([\S\s]*)$`x',
						$output, $matches);
				list(, $before, $cname, $block, $after) = $matches;
				list($block, $after) = $this->expand_block('`\bforeach\s+\$[-\w\d]+:`', 'endforeach', $block, $after);

				// check for modifiers
				$reverse = 0;
				if (substr($cname, -8) == "-reverse") {
					$reverse = 1;
					$cname = substr($cname, 0, -8);
				}

				$newblock = '';
				// parse loop once for each article/asset in the collection
				$collection = $context->get_collection($cname);
				if ($collection) {
					foreach ($reverse ? array_reverse($collection) : $collection as $newcontext) {
						$newblock .= $this->parse($block, $newcontext);
					}
				}

			// substitute 'if (!)@variable(='value'): ... endif' for contents, if condition is true
			} elseif ($blockmatches['if']) {
				preg_match(
						'`^([\S\s]*?)\b
							if\s+(!)?([\$@])([\w\d]+)(=([\'"])([^\\6]+)\\6)?:
								([\S\s]+?)\b
							endif\b
						([\S\s]*)$`x',
						$output, $matches);
				list(, $before, $neg, $type, $var, $is_comp, , $value, $block, $after) = $matches;
				list($block, $after) = $this->expand_block('`\bif\s+!?[\$@][\w\d]+:`', 'endif', $block, $after);

				// evaluate variable or collection to true or false
				$result = 0;
				if ($type == '$') {
					$result = ($context->getArticleCollections($var) || $context->getAssetCollections($var)) ? 1 : 0;
				} elseif ($type == '@') {
					if ($is_comp) {
						$result = $context->get_var($var) == $value;
					} else {
						$result = (bool)$context->get_var($var);
					}
				}

				// include block if result is true (or ! is present and result is false)
				$newblock = ($result xor $neg) ? $block : '';
			}
			
			$output = "{$before}{$newblock}{$after}";
		}

		// the following are evaluated only after dealing with all nested blocks,
		// so as to be rendered in the context of the most immediate containing block

		// substitute '@variable' with context's variable value
		$output = preg_replace_callback(
				'`@([\w\d]+)`',
				function($matches) use ($context) {
					return $context->get_var($matches[1]);
				},
				$output);
				
		// substitute '%[service]:asset(attr1: value, attr2: value)' with asset html
		// service and attribute list are optional
		// attribute values must escape commas or right parens
		// p.s. that's a fairly big-ass regex
		$output = preg_replace_callback(
				'`%(?:(\w+):)?    # service
				([\w\d\.]+|"[^"]+")    # asset name
				(?:\((\s*
					\w+\s*:\s*(?:[^,\)]|\\[,\)])+    # first attribute and value
					(?:,\s*\w+\s*:\s*(?:[^,\)]|\\[,\)])+)*    # any subsequent attributes
				\s*)\))?`x',
				function($matches) use ($context) {
					list(, $service, $name, $attrlist) = array_pad($matches, 4, '');
					return $context->render_asset($service, $name, $attrlist);
				},
				$output);
				
		// substitute '#function(arg1,arg2)' for output of '$this->f_function(arg1, arg2)'
		// IF function exists
		// warning: experimental. the argument part of the regex is pretty sloppy.
		while (preg_match('`#(\w+)\(([-/\w\d,]+)\)`', $output)) {
			preg_match(
					'`^([\S\s]*?)
						\#(\w+)    # function name
						\(([-/\w\d,]+)\)    # arguments
					([\S\s]*)$`x',
					$output, $matches);
			list(, $before, $function, $args, $after) = $matches;
			if (function_exists("f_$function")) {
				$result = call_user_func_array(array($this, "f_$function"), explode(',', $args));
				$output = "{$before}{$result}{$after}";
			}
		}

		return $output;
	}

	// expand block to close all nested structures
	protected function expand_block($pattern, $end, $block, $after) {
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
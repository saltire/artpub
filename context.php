<?php

// context parent class for articles and assets
// last modified oct 1, 2011

abstract class Context {

	protected $tree;
	protected $webroot;
	protected $path;
	protected $vars = array();

	public abstract function get_collection($cname);

	public function get_web_root() {
		return $this->webroot;
	}
	
	public function get_var($var) {
		return array_key_exists($var, $this->vars) ? $this->vars[$var] : null;
	}

	public function render_asset($service, $name, $attrlist) {
		$attrs = array();
		preg_match_all('`(\w+)\s*:\s*("[^"]*"|[^,]*)`', $attrlist, $matches, PREG_SET_ORDER);
		foreach ($matches as $match) {
			$attrs[$match[1]] = $match[2];
		}

		if ($service) {
			return $this->render_remote_asset($service, $name, $attrs);
		} else {
			return $this->render_local_asset($name, $attrs);
		}
	}

	private function render_local_asset($name, $attrs) {
		$name = trim(stripslashes($name), '"');

		// find a file with that filename, with or without an extension
		$filename = '';
		if (file_exists($this->tree->get_content_root() . "/$this->path/$name")) {
			$filename = $name;
		} else {
			$files = glob($this->tree->get_content_root() . "/$this->path/$name.*");
			if ($files) {
				$filename = basename($files[0]);
			}
		}

		if ($filename) {
			$uri = "{$this->webroot}/content/{$this->path}/" . $filename;
			$info = pathinfo($filename);

			switch ($info['extension']) {
				case 'jpg':
				case 'jpeg':
				case 'gif':
				case 'png':
					$attrlist = '';
					foreach ($attrs as $attr => $value) {
						$attrlist .= "$attr=\"$value\" ";
					}
					return "<img src=\"$uri\" $attrlist/>";

				case 'mp3':
					$id = preg_replace('`[^\w\d]`', '_', "audioplayer_$filename");
					$alt = $attrs['alt'] ? $attrs['alt'] : $info['filename'];
					$attrlist = '';
					foreach ($attrs as $attr => $value) {
						$attrlist .= ", $attr: \"$value\"";
					}
					return "<div class=\"audioplayer\"><p id=\"$id\">$alt</p></div>\n<script type=\"text/javascript\">AudioPlayer.embed(\"$id\", {soundFile: \"$uri\"$attrlist});</script>";
			}
		}
	}

	public function render_remote_asset($service, $id, $args) {
		switch ($service) {
			case 'vimeo':
				$args['url'] = "http://vimeo.com/$id";
				$video = json_decode($this->do_curl('http://vimeo.com/api/oembed.json', $args), 1);
				return "<div class=\"vimeo\">{$video['html']}</div>";
		}
	}

	private function do_curl($uri, $args) {
		$query = http_build_query($args);
		$ch = curl_init("$uri?$query");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		$result = curl_exec($ch);
		curl_close($ch);

		return $result;
	}
}
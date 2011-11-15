<?php

abstract class Context {

	protected $path;
	protected $vars = array();

	public abstract function getCollection($cname);

	public function getVar($var) {
		return array_key_exists($var, $this->vars) ? $this->vars[$var] : null;
	}

	public function renderAsset($service, $name, $attrlist) {
		$attrs = array();
		preg_match_all('`(\w+)\s*:\s*("[^"]*"|[^,]*)`', $attrlist, $matches, PREG_SET_ORDER);
		foreach ($matches as $match) {
			$attrs[$match[1]] = $match[2];
		}

		if ($service) {
			return $this->renderRemoteAsset($service, $name, $attrs);
		} else {
			return $this->renderLocalAsset($name, $attrs);
		}
	}

	private function renderLocalAsset($name, $attrs) {
		$name = trim(stripslashes($name), '"');

		// find a file with that filename, with or without an extension
		$filename = '';
		if (file_exists(CONTENT_ROOT . "/$this->path/$name")) {
			$filename = $name;
		} else {
			$files = glob(CONTENT_ROOT . "/$this->path/$name.*");
			if ($files) {
				$filename = basename($files[0]);
			}
		}

		if ($filename) {
			$uri = WEB_ROOT . "/content/{$this->path}/" . $filename;
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

	public function renderRemoteAsset($service, $id, $args) {
		switch ($service) {
			case 'vimeo':
				$args['url'] = "http://vimeo.com/$id";
				$video = json_decode($this->doCurl('http://vimeo.com/api/oembed.json', $args), 1);
				return "<div class=\"vimeo\">{$video['html']}</div>";
		}
	}

	private function doCurl($uri, $args) {
		$query = http_build_query($args);
		$ch = curl_init("$uri?$query");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		$result = curl_exec($ch);
		curl_close($ch);

		return $result;
	}
}
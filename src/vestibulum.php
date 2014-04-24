<?php
namespace vestibulum;

use SplFileInfo;

/**
 * Vestibulum: Really deathly simple CMS
 *
 * @author Roman Ožana <ozana@omdesign.cz>
 */
class Vestibulum extends \stdClass {

	use Config;
	use Request;

	/** @var File */
	public $file;
	/** @var string */
	public $content;

	public function __construct() {
		$this->requires();
		$this->file = $this->getFile((array)$this->config()->meta);
		$this->functions();
	}

	/**
	 * Requires PHP first
	 */
	public function requires() {
		is_file($php = getcwd() . $this->getRequest() . '.php') ? include_once $php : null;
		is_file($php = $this->src() . $this->getRequest() . '.php') ? include_once $php : null;
	}

	/**
	 * Auto include functions.php
	 */
	public function functions() {
		global $cms;
		$cms = $this; // create link to $this
		is_file($functions = getcwd() . '/functions.php') ? include_once $functions : null;
	}

	/**
	 * Return current file
	 *
	 * @param array $meta
	 * @return File
	 */
	public function getFile(array $meta = []) {
		$file = File::fromRequest($this->src() . $this->getRequest(), $meta);
		if ($file === null) {
			header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
			$file = File::fromRequest($this->src() . '/404', $meta);
		}
		return $file ? : new File($this->src(), $meta, '<h1>404 Page not found</h1>');
	}


	/**
	 * TODO need to be change to soemt
	 *
	 * @return string
	 */
	protected function render() {
		// Content

		$this->content = str_replace('%url%', $this->url(), $this->file->getContent());

		// FIXME and find better way how to save to cache
		if ($this->file->getExtension() === 'md') {

			// @see https://github.com/erusev/parsedown/pull/105
			$this->content = preg_replace('/<!--(.*)-->/Uis', '', $this->content, 1); // first only

			$cache = isset($this->config()->markdown['cache']) && $this->config()->markdown['cache'] ? realpath(
				$this->config()->markdown['cache']
			) : false;
			if ($cache && is_dir($cache) && is_writable($cache)) {
				$cacheFile = $cache . '/' . md5($this->file);
				if (!is_file($cacheFile) || @filemtime($this->file) > filemtime($cacheFile)) {
					$this->content = \Parsedown::instance()->parse($this->content);
					file_put_contents($cacheFile, $this->content);
				} else {
					$this->content = file_get_contents($cacheFile);
				}
			} else {
				$this->content = \Parsedown::instance()->parse($this->content);
			}
		}

		$ext = pathinfo($this->file->template, PATHINFO_EXTENSION);

		// phtml - for those who have an performance obsession :-)

		if ($ext === 'phtml' || $ext === 'php') {
			ob_start();
			extract(get_object_vars($this));
			require($this->file->template);
			$output = ob_get_contents();
			ob_end_clean();
			return $output;
		}

		// twig

		if ($ext === 'twig') {

			$loader = new \Twig_Loader_Filesystem($this->config->templates);
			$twig = new \Twig_Environment($loader, $this->config->twig);
			$twig->addExtension(new \Twig_Extension_Debug());
			$twig->addExtension(new \Twig_Extension_StringLoader());

			// undefined filters callback
			$twig->registerUndefinedFilterCallback(
				function ($name) {
					return function_exists($name) ?
						new \Twig_SimpleFilter($name, function () use ($name) {
							return call_user_func_array($name, func_get_args());
						}, ['is_safe' => ['html']]) : false;
				}
			);

			$twig->addFunction('url', new \Twig_SimpleFunction('url', [$this, 'url']));

			// undefined functions callback
			$twig->registerUndefinedFunctionCallback(
				function ($name) {
					return function_exists($name) ?
						new \Twig_SimpleFunction($name, function () use ($name) {
							return call_user_func_array($name, func_get_args());
						}) : false;
				}
			);

			if ($this->file->twig) {
				$this->content = twig_template_from_string($twig, $this->content)->render(get_object_vars($this));
			}

			return $twig->render($this->file->template, get_object_vars($this));
		}
	}

	/**
	 * Render string content
	 *
	 * @return string
	 */
	public function __toString() {
		try {
			return $this->render();
		} catch (\Exception $e) {
			return $e->getMessage();
		}
	}
}
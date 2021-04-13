<?php

require_once('traits/block-attributes.php');
require_once('traits/block-metakeys.php');

// Basic Blocks Class ---------------------------------------------------------]

class zukit_Blocks extends zukit_Addon {

	protected $attributes = [];
	protected $metakeys = [];

	private $blocks_available = false;
	private $block_names = null;
	private $frontend_names = null;
	private $handle = null;
	private $namespace = null;

	// We can only have one 'zukit-blocks' script loaded and therefore
    // store its status in a static property so that we can avoid repeated 'enqueue' calls.
    private static $zukit_loaded = false;

	private static $zukit_handle = 'zukit-blocks';

	private static $colors_filename = 'zukit-colors';

	// Add functions for blocks with attributes
	use zukit_BlockAttributes;

	// Add meta keys for blocks
	use zukit_BlockMeta;

	protected function construct_more() {
		$this->blocks_available = function_exists('register_block_type');
		$this->handle = $this->get('blocks.handle', true) ?? $this->prefix_it('blocks');
		$this->namespace = $this->get('blocks.namespace', true) ?? $this->get('prefix', true);
		if($this->blocks_available) {
			// add_action('init', [$this, 'register_blocks'], 99);
			add_action('enqueue_block_editor_assets', [$this, 'editor_assets']);
			add_action('enqueue_block_assets', [$this, 'block_assets']);
			add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
		}
	}

	final public static function defaults() {
		return [
			'blocks'			=> [],
			'frontend_blocks'	=> [],
			'namespace'			=> null,

			// instance of class inheriting the class 'zukit_Blocks' if was created
			'instance'			=> null,

			// scripts and styles we should load for the WordPress Block Editor (Gutenberg)
			'load_zukit'		=> true,
			'load_css'			=> true,
			'load_frontend_css'	=> true,
			'load_frontend_js'	=> false,
			'handle'			=> null,

			'dynamic'			=> false,
			'metakeys'			=> false,
			'no_excerpt'		=> false,
		];
	}

	protected function is_blocks_config($key) {
		$value = $this->get('blocks.'.$key, true);
		return $value === true;
	}

	protected function get_block_args($block) {
		$args = [
			'editor_script'	=> $this->handle,
			'editor_style'	=> $this->is_blocks_config('load_css') ? $this->handle : null,
		];

		$frontend_blocks = $this->get_frontend_blocks();
		return array_merge($args, in_array($block, $frontend_blocks) ? $this->plugin->frontend_handles() : []);
	}

	public function init() {
		if($this->blocks_available) {
			$this->register_blocks();
		}
	}

	public function register_blocks() {

		// return early if not available
		if(!$this->blocks_available) return;

		foreach($this->get_blocks() as $block) {
			$args = $this->get_block_args($block);
			register_block_type($block, $args);
		}

		// add block attributes, generate 'render_callbak' and register these blocks
		if($this->is_blocks_config('dynamic')) $this->register_blocks_with_attributes();

		// register meta keys to make them be accessible for blocks via the REST API
		if($this->is_blocks_config('metakeys')) $this->register_metakeys();

		// add list of blocks which should be avoided during apply_filters('the_content'...)
		// because we need remove any Gutenberg block which use 'get_excerpt' before apply_filters('the_content'...)
		if($this->is_blocks_config('no_excerpt')) {
			$no_excerpt_blocks = $this->no_excerpt();
			if(!empty($no_excerpt_blocks)) {
				add_filter('zukit_no_excerpt_blocks', function($blocks) use($no_excerpt_blocks) {
					return array_merge($blocks, $no_excerpt_blocks);
				}, 10, 1);
			}
		}
	}

	// Scripts & Styles management --------------------------------------------]

	private function script_defaults() {
		return [
			// front-end script & style
			'script'	=> [
				'add_prefix'	=> false,
				'deps'			=> ['wp-edit-post'],
				'data'			=> [$this, 'jsdata_defaults'],
				'handle'		=> $this->handle,
			],
			'style'		=> [
				'add_prefix'	=> false,
				'deps'			=> ['wp-edit-post'],
				'handle'		=> $this->handle,
			],
		];
	}

	private function jsdata_defaults() {
		return array_merge([
			'jsdata_name'	=> $this->prefix_it('blocks_data', '_'),
		], $this->plugin->api_basics());
	}

	protected function js_params($defaults = null) {
		$params = $this->plugin->get(is_null($defaults) ? 'blocks.script' : 'script', [], $defaults);
		$params['data'] = is_callable($params['data'] ?? null) ? call_user_func($params['data'], false) : $params['data'] ?? null;
		return $params;
	}

	protected function css_params($defaults = null) {
		return $this->plugin->get(is_null($defaults) ? 'blocks.style' : 'style', [], $defaults);
	}

	// 'editor_assets' will be called only in the WordPress Block Editor (Gutenberg)
	// here we load basic framework scripts and also theme/plugin scripts for the WordPress Block Editor
	public function editor_assets() {
		$this->enqueue_zukit_blocks();
		$this->enqueue_blocks_style_and_script();
	}

	// 'block_assets' will be called in the Block Editor as well as on front-end
	// here we only load front-end theme/plugin scripts for the Block Editor and only if requested
	// we do not load these scripts for the front-end beacuse
	// they will be loaded automatically (we specified their handles when registering blocks)
	public function block_assets() {
		if(is_admin()) {
			$this->plugin->force_frontend_enqueue(
				$this->get('blocks.load_frontend_css', true),
				$this->get('blocks.load_frontend_js', true)
			);
			$this->plugin->blocks_enqueue_more(false, null, null);
		}
	}

	// 'frontend_assets' will be called only on front-end
	// here we just let the plugin/theme load additional scripts/styles if required
	// we also parse the block parameters and pass them to the 'blocks_enqueue_more' method
	// so that the plugin/theme can make the right decision about loading
	public function frontend_assets() {
		$frontend_blocks = $this->get_frontend_blocks();
		foreach($frontend_blocks as $block) {
			$attrs = $this->check_block($block);
			if($attrs !== false) {
				$this->plugin->blocks_enqueue_more(true, $this->full_name($block), $attrs);
				break;
			}
		}
	}

	private function enqueue_zukit_blocks() {
		if(self::$zukit_loaded === false && $this->is_blocks_config('load_zukit')) {
			// dependencies for Zukit Blocks script & styles
			$js_deps = ['wp-edit-post'];
			// 	'wp-blocks',
			// 	'wp-i18n',
			// 	'wp-element',
			// 	'wp-plugins',
			// 	'wp-components',
			// 	'wp-edit-post'

			$css_deps = ['wp-edit-post'];
			// params for 'zukit-blocks' script
			$zukit_params = [
				'absolute'		=> true,
				'add_prefix'	=> false,
				'data'			=> [
					'jsdata_name'	=> 'zukit_jsdata',
					'colors'		=> $this->get_colors(),
				],
				'deps'			=> $js_deps,
				'handle'		=> self::$zukit_handle,
			];
			$this->admin_enqueue_script(self::$zukit_handle, $zukit_params);
			$this->admin_enqueue_style(self::$zukit_handle, array_merge($zukit_params, ['deps' => $css_deps, 'data' => null]));
			// Parameters: [$handle, $domain, $path]. WordPress will check for a file in that path
			// with the format ${domain}-${locale}-${handle}.json as the source of translations
        	wp_set_script_translations('zukit', 'zukit', $this->plugin->zukit_dirname('lang'));
			self::$zukit_loaded = true;
		}
	}

	private function enqueue_blocks_style_and_script() {

		$css_params = $this->plugin->params_validated(
			$this->css_params(),
			$this->css_params($this->script_defaults())
		);

		$js_params = $this->plugin->params_validated(
			$this->js_params(),
			$this->js_params($this->script_defaults())
		);

		// add dependency to Zukit Blocks if required
		if($this->is_blocks_config('load_zukit')) {
			$css_params['deps'][] = self::$zukit_handle;
			$js_params['deps'][] = self::$zukit_handle;
		}

		if($this->is_blocks_config('load_css')) {
			$this->admin_enqueue_style($this->handle, $css_params);
		}

		$this->admin_enqueue_script($this->handle, $js_params);
	}

	// Blocks list, parsing and other helpers ---------------------------------]

	// normalize block name to include namespace, if provided as non-namespaced
	protected function full_name($name) {
		return strpos($name, '/') === false ? ($this->namespace.'/'.$name) : $name;
	}

	protected function is_block($test, $block_name) {
		return $test === $block_name || $test === $this->full_name($block_name);
	}

	// determine whether the current post contains a specific block type
	// and parse its attributes (if found)
	private function check_block($name) {

	    $wp_post = get_post();
		$post = $wp_post instanceof WP_Post ? $wp_post->post_content : null;
		$block_name = $this->full_name($name);

	    // test for existence of block by its fully qualified name
	    $has_block = false !== strpos($post, '<!-- wp:' . $block_name . ' ');

	    if($has_block) {
			$preg_name = str_replace('/', '\/', $block_name);
			preg_match_all('/<!-- wp:' . $preg_name . ' (.*?) -->/', $post, $matches);

			$attrs = [];
			foreach($matches[1] ?? [] as $attr_string) {
				$json = json_decode(trim($attr_string), true);
				$attrs[] = $json ?? null;
			}
			return $attrs;
	    }

	    return $has_block;
	}

	// create a list of _full_ block names
	private function get_blocks() {
		if($this->block_names === null) {
			$blocks = $this->get('blocks.blocks', true);
			$this->block_names = [];
			foreach((is_array($blocks) ? $blocks : [$blocks]) as $block) {
				$this->block_names[] = $this->full_name($block);
			}
		}
		return $this->block_names;
	}

	// create a list of _full_ block names available on the front-end
	private function get_frontend_blocks() {
		if($this->frontend_names === null) {
			$frontend_blocks = $this->get('blocks.frontend_blocks', true) ?? $this->get_blocks();
			$this->frontend_names = [];
			foreach((is_array($frontend_blocks) ? $frontend_blocks : [$frontend_blocks]) as $block) {
				$this->frontend_names[] = $this->full_name($block);
			}
		}
		return $this->frontend_names;
	}

	private function get_colors() {
		$colors = [];
		$filepath = $this->plugin->get_zukit_filepath(true, self::$colors_filename, false);
		if(file_exists($filepath)) {
			$content = file_get_contents($filepath);
			if($content === false) return $colors;
			foreach(explode('}', $content) as $line) {
				if(empty(trim($line))) continue;
				$name = preg_match('/.js_([^\{]+)/', $line, $matches) ? $matches[1] : 'error';
				$color = preg_match('/color\:(.+)/', $line, $matches) ? $matches[1] : 'red';
				$short_name = str_replace('_color', '', $name);
				if(array_key_exists($short_name, $colors)) {
					$this->logc('Duplicate name when creating Zukit Colors!', [
						'line'			=> $line,
		                'name'			=> $name,
		                'color'			=> $color,
		                'short_name'	=> $short_name,
						'colors'		=> $colors,
		            ]);
				}
				$colors[$short_name] = $color;
			}
		}
		return $colors;
	}
}

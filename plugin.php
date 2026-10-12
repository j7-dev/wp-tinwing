<?php
/**
 * Plugin Name:       Wp Tinwing
 * Plugin URI:        wp-tinwing
 * Description:       WP版本-天永保險系統
 * Version:           1.0.87
 * Requires at least: 5.7
 * Requires PHP:      8.0
 * Author:            Ren
 * Author URI:        https://github.com/s0985514623/wp-tinwing
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp_tinwing
 * Domain Path:       /languages
 * Tags: your tags
 */

declare ( strict_types=1 );

namespace J7\WpTinwing;

if ( ! \class_exists( 'J7\WpTinwing\Plugin' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';

	/**
	 * Class Plugin
	 */
	final class Plugin {
		use \J7\WpUtils\Traits\PluginTrait;
		use \J7\WpUtils\Traits\SingletonTrait;

		/**
		 * Constructor
		 */
		public function __construct() {

			// $this->required_plugins = array(
			// array(
			// 'name'     => 'WooCommerce',
			// 'slug'     => 'woocommerce',
			// 'required' => true,
			// 'version'  => '7.6.0',
			// ),
			// array(
			// 'name'     => 'WP Toolkit',
			// 'slug'     => 'wp-toolkit',
			// 'source'   => 'Author URL/wp-toolkit/releases/latest/download/wp-toolkit.zip',
			// 'required' => true,
			// ),
			// );

			$this->init(
				[
					'app_name'    => 'Wp Tinwing',
					'github_repo' => 'https://github.com/s0985514623/wp-tinwing',
					'callback'    => [ Bootstrap::class, 'instance' ],
				]
			);
		}
	}

	Plugin::instance();
}

<?php
/**
 * Plugin Name:       Store Locator for OpenStreetMap
 * Description:       A store locator built on Leaflet and OpenStreetMap. No Google Maps API key, no billing account, no third-party map cookies.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Asymetria
 * Author URI:        https://asymetria.com.pl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       store-locator-for-openstreetmap
 *
 * Every declaration below is guarded — defined() for constants, class_exists()
 * for classes, function_exists() for functions — and that is not belt and
 * braces. PHP binds unconditional class and function declarations at compile
 * time, so an early return at the top of a file does not save a site from a
 * second copy of this code loaded from somewhere else: a theme's functions.php,
 * a bundled copy inside another plugin. That failure mode is a white screen on
 * a production site, not a notice.
 *
 * There is deliberately no Plugin URI header. WordPress.org rejects a
 * submission when Plugin URI and Author URI hold the same value.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SLOSM_VERSION' ) ) {
	define( 'SLOSM_VERSION', '1.0.0' );
	define( 'SLOSM_FILE', __FILE__ );
	define( 'SLOSM_DIR', plugin_dir_path( __FILE__ ) );
	define( 'SLOSM_URL', plugin_dir_url( __FILE__ ) );
}

require_once __DIR__ . '/includes/class-autoloader.php';

if ( ! function_exists( 'slosm_bootstrap' ) ) {

	/**
	 * Registers the autoloader and boots the plugin.
	 *
	 * @return void
	 */
	function slosm_bootstrap(): void {
		\Asymetria\StoreLocator\Autoloader::register();
		\Asymetria\StoreLocator\Plugin::instance()->boot();
	}
}

slosm_bootstrap();

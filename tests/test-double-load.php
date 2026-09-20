<?php
/**
 * Proves the guarded declarations survive the plugin's code being loaded twice.
 *
 * This has to run in a child process. A double load either survives or is a
 * compile-time fatal, and a fatal in this process would take the whole suite
 * with it before it could be reported.
 *
 * Two things the child does that are worth explaining:
 *
 * 1. It requires the class files a second time directly, not only the main
 *    plugin file twice. The main file pulls its classes in with require_once
 *    against __DIR__, so requiring it twice loads each class file exactly once
 *    and would never exercise a class_exists() guard at all — the test would
 *    stay green with every one of them deleted. Requiring the class files again
 *    is the theme-paste case the guards exist for: the same class body handed
 *    to the compiler twice.
 *
 * 2. Two of those requires are for class-store.php, two more for class-geo.php,
 *    two more for class-store-repository.php and two more for
 *    class-geocoder.php, and the repetition is not a
 *    slip. The autoloader only
 *    reaches a class something asks for, and booting the plugin asks for
 *    Autoloader, Plugin, Post_Type, Store_Repository and Rest_Controller —
 *    never Store, Geo or Geocoder, which nothing instantiates or calls until a
 *    request needs locations or an address. So
 *    the first require of class-store.php is its first load, and only the
 *    second hands its body to the compiler twice. Every class that joins this
 *    list has to be loaded twice one way or the other, or its class_exists()
 *    guard is decoration and this test will stay green with the guard deleted.
 *
 *    Store_Repository, Rest_Controller, Assets and Shortcode are the four the
 *    boot already reaches through the autoloader, so for them the *first*
 *    require below is already the second compile. Requiring them twice all the
 *    same costs nothing and keeps the list one rule rather than two: every
 *    class file, twice.
 *
 * 3. It turns warnings into throwables before loading anything. Re-defining a
 *    constant is only a warning, so without this the defined() guard around
 *    SLOSM_VERSION could be deleted and the child would still print its marker.
 *
 * The child script is written to a temporary file rather than passed to php -r.
 * On Windows escapeshellarg() replaces double quotes with spaces, which quietly
 * mangles any inlined PHP that contains a string literal.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

describe(
	'double load',
	function () {

		it(
			'survives the plugin being loaded twice',
			function () {
				$root      = dirname( __DIR__ );
				$plugin    = $root . '/store-locator-for-openstreetmap.php';
				$child     = sprintf(
					'<?php' . "\n"
					. 'set_error_handler( static function ( $errno, $message, $file, $line ) {' . "\n"
					. 'throw new ErrorException( $message, 0, $errno, $file, $line );' . "\n"
					. '} );' . "\n"
					. 'define( %s, %s );' . "\n"
					// boot() builds a Store_Repository and an Admin, and several of
					// those classes' constants are WordPress time constants, so PHP
					// evaluates them the moment the class is first used.
					// wp-settings.php defines them in default-constants.php before
					// any plugin file is loaded, so a real site always has them; this
					// bare process does not, and without these three lines the child
					// dies on a missing constant and reports it as a failed double
					// load. Task 17 added the hour, and this is where it was noticed.
					. 'define( %s, 60 );' . "\n"
					. 'define( %s, 3600 );' . "\n"
					. 'define( %s, 86400 );' . "\n"
					. 'function plugin_dir_path( $file ) { return dirname( $file ) . %s; }' . "\n"
					. 'function plugin_dir_url( $file ) { return %s; }' . "\n"
					. 'function add_action( $hook, $cb, $priority = 10, $args = 1 ) { return true; }' . "\n"
					. 'function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { return true; }' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'echo %s;' . "\n",
					var_export( 'ABSPATH', true ),
					var_export( $root . '/', true ),
					var_export( 'MINUTE_IN_SECONDS', true ),
					var_export( 'HOUR_IN_SECONDS', true ),
					var_export( 'DAY_IN_SECONDS', true ),
					var_export( '/', true ),
					var_export( 'https://example.test/wp-content/plugins/store-locator-for-openstreetmap/', true ),
					var_export( $plugin, true ),
					var_export( $plugin, true ),
					var_export( $root . '/includes/class-autoloader.php', true ),
					var_export( $root . '/includes/class-plugin.php', true ),
					var_export( $root . '/includes/class-post-type.php', true ),
					var_export( $root . '/includes/class-store.php', true ),
					var_export( $root . '/includes/class-store.php', true ),
					var_export( $root . '/includes/class-geo.php', true ),
					var_export( $root . '/includes/class-geo.php', true ),
					var_export( $root . '/includes/class-store-repository.php', true ),
					var_export( $root . '/includes/class-store-repository.php', true ),
					var_export( $root . '/includes/class-geocoder.php', true ),
					var_export( $root . '/includes/class-geocoder.php', true ),
					var_export( $root . '/includes/class-rest-controller.php', true ),
					var_export( $root . '/includes/class-rest-controller.php', true ),
					var_export( $root . '/includes/class-assets.php', true ),
					var_export( $root . '/includes/class-assets.php', true ),
					var_export( $root . '/includes/class-shortcode.php', true ),
					var_export( $root . '/includes/class-shortcode.php', true ),
					var_export( $root . '/admin/class-admin.php', true ),
					var_export( $root . '/admin/class-admin.php', true ),
					var_export( 'SURVIVED-DOUBLE-LOAD', true )
				);
				// No .php suffix: the CLI binary runs a file whatever it is
				// called, and appending one would orphan the file tempnam()
				// itself created.
				$script    = tempnam( sys_get_temp_dir(), 'slosm' );
				$command   = escapeshellarg( PHP_BINARY )
					. ' -d display_errors=1 -d error_reporting=-1 '
					. escapeshellarg( $script ) . ' 2>&1';

				file_put_contents( $script, $child );

				try {
					$output = (string) shell_exec( $command );
				} finally {
					unlink( $script );
				}

				// The child's own output goes into the message. A failure that
				// says only "marker missing" hides the fatal that caused it.
				assert_contains(
					'SURVIVED-DOUBLE-LOAD',
					$output,
					"loading the plugin twice did not survive; the child process said:\n" . trim( $output )
				);
			}
		);
	}
);

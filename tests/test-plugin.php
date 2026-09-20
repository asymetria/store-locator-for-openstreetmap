<?php
/**
 * Proves the plugin object's runtime state: one instance, booted at most once.
 *
 * Separate from tests/test-double-load.php on purpose. That file is about
 * compile-time declaration binding in a child process; this one is about
 * runtime state in this one. Only the name they share is similar.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-plugin.php';
require_once dirname( __DIR__ ) . '/admin/class-settings-screen.php';
require_once dirname( __DIR__ ) . '/admin/class-shortcode-generator.php';
// boot() constructs a Post_Type, a Shortcode, a Store_Repository, a
// Rest_Controller and an Admin, and the suite runs without the autoloader.
require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
require_once dirname( __DIR__ ) . '/includes/class-store.php';
require_once dirname( __DIR__ ) . '/includes/class-store-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-geo.php';
require_once dirname( __DIR__ ) . '/includes/class-geocoder.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';
require_once dirname( __DIR__ ) . '/includes/class-assets.php';
require_once dirname( __DIR__ ) . '/includes/class-shortcode.php';
require_once dirname( __DIR__ ) . '/admin/class-admin.php';
require_once dirname( __DIR__ ) . '/admin/class-locations-list.php';
require_once dirname( __DIR__ ) . '/admin/class-bulk-geocode.php';

use Asymetria\StoreLocator\Plugin;

describe(
	'plugin',
	function () {

		it(
			'boots at most once',
			function () {
				// Closures bound to the class scope rather than reflection:
				// ReflectionProperty::setAccessible() is deprecated in PHP 8.5.
				$construct = Closure::bind(
					static function () {
						return new Plugin();
					},
					null,
					Plugin::class
				);
				$read      = Closure::bind(
					static function ( Plugin $plugin ) {
						return $plugin->booted;
					},
					null,
					Plugin::class
				);

				// A fresh, unbooted instance rather than the singleton, so this
				// case does not depend on whether another case booted first.
				$plugin = $construct();

				assert_false( $read( $plugin ) );

				$plugin->boot();

				assert_true( $read( $plugin ) );

				// Registrations, not hook names. Counting the outer arrays
				// counts distinct hooks, so a second boot() adding another
				// callback to the same 'init' hook would leave the total at one
				// and slip straight through — which is exactly the regression
				// this case exists to catch.
				$registrations = static function (): int {
					$total = 0;

					foreach ( array( 'actions', 'filters' ) as $kind ) {
						foreach ( $GLOBALS['slosm_stub'][ $kind ] as $callbacks ) {
							$total += count( $callbacks );
						}
					}

					return $total;
				};

				$hooks = $registrations();

				// Without this, everything below would hold just as well for a
				// boot() that registered nothing at all.
				assert_true( 0 < $hooks, 'boot() registered no hooks, so the comparison below proves nothing' );

				$plugin->boot();

				assert_same(
					$hooks,
					$registrations(),
					'a second boot() registered hooks again'
				);
			}
		);

		it(
			'hands out one instance',
			function () {
				assert_same( Plugin::instance(), Plugin::instance() );
			}
		);
	}
);

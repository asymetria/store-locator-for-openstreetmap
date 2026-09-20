<?php
/**
 * Proves the screen itself: where it is, who may use it, and what it prints.
 *
 * WHAT THE SETTINGS API GIVES YOU, AND WHAT IT DOES NOT
 * ====================================================
 * It gives you the save. `settings_fields()` prints the nonce
 * `{$group}-options`, `wp-admin/options.php` calls
 * `check_admin_referer( $option_page . '-options' )` at line 244 of WordPress
 * 6.9.1, refuses any option not in `$allowed_options[ $option_page ]`, and
 * demands `manage_options` — hard-coded at line 29, moved only by the
 * `option_page_capability_{$option_page}` filter. None of that is this
 * plugin's, and none of it can be got wrong here.
 *
 * It gives you nothing at all for three things this screen has:
 *
 * - **The page.** `add_submenu_page()`'s capability is what core's
 *   `user_can_access_admin_page()` enforces before the callback runs
 *   (wp-admin/includes/menu.php line 371), and it is this plugin's to choose.
 *   Choosing anything below `manage_options` would be a screen an editor can
 *   open and cannot save — a 403 they can do nothing about.
 * - **The clear-cache button.** It is a different request with a different
 *   side effect and it goes to admin-post.php, which checks nothing. Its nonce
 *   and its capability check are this file's subject.
 * - **The unchecked checkbox.** The Settings API has no opinion; an unchecked
 *   box posts nothing, and "nothing" is how this option's sanitiser spells
 *   "keep what is stored". The hidden companion is what makes off possible.
 *
 * WHAT "CLEAR CACHE" HAS TO ACTUALLY DO
 * =====================================
 * Both Store_Repository::CACHE_PREFIX and Geocoder::CACHE_PREFIX carry the same
 * warning in their docblocks: a sweep of wp_options for
 * `option_name LIKE '_transient_slosm_%'` finds nothing at all on a site with a
 * persistent object cache, because the transients live in Redis under keys no
 * SQL can see. So the cases below do not assert that anything was deleted.
 * They assert that the keys moved and that the old entries were **left exactly
 * where they were** — which is what an invalidation that works on every site
 * looks like from the outside, and which a sweep would fail.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-geo.php';
require_once dirname( __DIR__ ) . '/includes/class-store.php';
require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
require_once dirname( __DIR__ ) . '/includes/class-store-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-geocoder.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';
require_once dirname( __DIR__ ) . '/includes/class-assets.php';
require_once dirname( __DIR__ ) . '/includes/class-shortcode.php';
require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-plugin.php';
require_once dirname( __DIR__ ) . '/admin/class-admin.php';
// boot() builds these two as well, and one case here boots a plugin to see
// which repository the screen was handed. The suite has no autoloader, and a
// file that relied on another test file having required them would pass in the
// suite and fail on its own.
require_once dirname( __DIR__ ) . '/admin/class-locations-list.php';
require_once dirname( __DIR__ ) . '/admin/class-bulk-geocode.php';
require_once dirname( __DIR__ ) . '/admin/class-settings-screen.php';
require_once dirname( __DIR__ ) . '/admin/class-shortcode-generator.php';

use Asymetria\StoreLocator\Admin\Admin;
use Asymetria\StoreLocator\Admin\Settings_Screen;
use Asymetria\StoreLocator\Geocoder;
use Asymetria\StoreLocator\Plugin;
use Asymetria\StoreLocator\Post_Type;
use Asymetria\StoreLocator\Settings;
use Asymetria\StoreLocator\Store_Repository;

if ( ! function_exists( 'slosm_settings_screen' ) ) {
	/**
	 * A screen whose "leave" is a recorder rather than exit.
	 *
	 * The seam is the one Geocoder takes for its clock and its sleeper, and for
	 * the same reason: the production behaviour — redirect, then stop the
	 * request — cannot be exercised by a test process that has to carry on.
	 *
	 * @return Settings_Screen
	 */
	function slosm_settings_screen(): Settings_Screen {
		return new Settings_Screen(
			new Store_Repository(),
			new Geocoder(),
			static function (): void {
				$GLOBALS['slosm_stub']['left'] = true;
			}
		);
	}
}

if ( ! function_exists( 'slosm_settings_render' ) ) {
	/**
	 * The screen's html, rendered for a user who may see it.
	 *
	 * @param string $tab Tab to ask for, or '' for whichever is first.
	 * @return string
	 */
	function slosm_settings_render( string $tab = '' ): string {
		$GLOBALS['slosm_stub']['capabilities'][ Settings_Screen::CAPABILITY ] = true;

		if ( '' !== $tab ) {
			$_GET[ Settings::TAB_ARG ] = $tab;
		}

		$screen = slosm_settings_screen();
		$screen->add_fields();

		ob_start();
		$screen->render();
		$html = (string) ob_get_clean();

		unset( $_GET[ Settings::TAB_ARG ] );

		return $html;
	}
}

describe(
	'Settings_Screen — where the screen is',
	function () {
		it(
			'adds one page, under the locations menu rather than under Settings',
			function () {
				slosm_settings_screen()->add_page();

				$pages = $GLOBALS['slosm_stub']['admin_pages'];

				assert_same( 1, count( $pages ) );
				assert_same( 'edit.php?post_type=' . Post_Type::POST_TYPE, $pages[0]['parent_slug'] );
				assert_same( Settings::PAGE, $pages[0]['menu_slug'] );
			}
		);

		it(
			'asks for manage_options, which is the capability options.php will demand anyway',
			function () {
				slosm_settings_screen()->add_page();

				assert_same( 'manage_options', $GLOBALS['slosm_stub']['admin_pages'][0]['capability'] );
				assert_same( 'manage_options', Settings_Screen::CAPABILITY );
			}
		);

		it(
			'hands core its own render method, so the page is not a file include',
			function () {
				$screen = slosm_settings_screen();
				$screen->add_page();

				$callback = $GLOBALS['slosm_stub']['admin_pages'][0]['callback'];

				assert_true( is_array( $callback ) );
				assert_true( $callback[0] === $screen );
				assert_same( 'render', $callback[1] );
			}
		);

		it(
			'is handed the repository everything else on the request shares',
			function () {
				// The only place the sharing is observable, which is why it takes a
				// bound closure: the generation lives in an option, so two
				// instances agree about the cache whatever happens, and
				// flush_cache( true ) bypasses the one piece of per-instance state
				// there is. Task 20 pinned the same thing the same way for the
				// same reason — and it matters the day the flush stops being
				// forced, which is a one-word edit.
				$construct = Closure::bind(
					static function () {
						return new Plugin();
					},
					null,
					Plugin::class
				);

				$plugin = $construct();
				$plugin->boot();

				$callbacks = $GLOBALS['slosm_stub']['actions']['admin_menu'] ?? array();

				// Two since Task 22 — this screen and the shortcode generator —
				// so the one under test is picked by class rather than by
				// position. tests/test-cache-invalidation.php is where the
				// count itself is asserted.
				$screen = null;

				foreach ( $callbacks as $callback ) {
					if ( $callback['callback'][0] instanceof Settings_Screen ) {
						$screen = $callback['callback'][0];
					}
				}

				assert_true( $screen instanceof Settings_Screen, 'the settings page is not on admin_menu' );

				$of_plugin = Closure::bind(
					static function ( $object ) {
						return $object->repository;
					},
					null,
					Plugin::class
				);

				$of_screen = Closure::bind(
					static function ( $object ) {
						return $object->repository;
					},
					null,
					Settings_Screen::class
				);

				assert_true( $of_screen( $screen ) === $of_plugin( $plugin ) );
			}
		);

		it(
			'answers with a hook suffix Assets can recognise this screen by',
			function () {
				$hook = slosm_settings_screen()->add_page();

				assert_true( str_ends_with( $hook, '_page_' . Settings::PAGE ), $hook );
			}
		);

		it(
			'builds its own url with the post type on it, which is what makes the menu highlight',
			function () {
				$url = Settings_Screen::url( 'advanced' );

				assert_contains( 'post_type=' . Post_Type::POST_TYPE, $url );
				assert_contains( 'page=' . Settings::PAGE, $url );
				assert_contains( Settings::TAB_ARG . '=advanced', $url );
			}
		);
	}
);

describe(
	'Settings::register() — the option, and the filter that is the only gate',
	function () {
		it(
			'registers the option this plugin reads, in a group of its own',
			function () {
				Settings::register();

				$registered = $GLOBALS['slosm_stub']['settings'];

				assert_same( 1, count( $registered ) );
				assert_same( Settings::GROUP, $registered[0]['group'] );
				assert_same( Settings::OPTION, $registered[0]['option'] );
			}
		);

		it(
			'hands core the sanitiser, which is what makes every write go through it',
			function () {
				Settings::register();

				$args = $GLOBALS['slosm_stub']['settings'][0]['args'];

				assert_same( array( Settings::class, 'sanitise' ), $args['sanitize_callback'] );

				// And core really hangs it on the filter update_option() runs.
				assert_true( isset( $GLOBALS['slosm_stub']['filters'][ 'sanitize_option_' . Settings::OPTION ] ) );
			}
		);

		it(
			'does not lower the capability options.php demands',
			function () {
				Settings::register();
				slosm_settings_screen()->add_page();

				// The one filter that could put this option in an editor's
				// reach is `option_page_capability_{$option_page}`, which
				// wp-admin/options.php applies at line 44 of WordPress 6.9.1 to
				// move the manage_options it hard-codes at line 29. Not adding
				// it is the whole of the argument in CAPABILITY's docblock, and
				// this is the only place that decision is visible: nothing about
				// a missing filter shows up anywhere else.
				assert_false(
					isset( $GLOBALS['slosm_stub']['filters'][ 'option_page_capability_' . Settings::GROUP ] ),
					'something moved the capability this option saves under'
				);

				// The control, in the same case: registering really happened,
				// so this is not passing because nothing ran.
				assert_true( isset( $GLOBALS['slosm_stub']['filters'][ 'sanitize_option_' . Settings::OPTION ] ) );
				assert_same( 1, count( $GLOBALS['slosm_stub']['admin_pages'] ) );
			}
		);

		it(
			'declares the option an array and keeps it out of the REST API',
			function () {
				Settings::register();

				$args = $GLOBALS['slosm_stub']['settings'][0]['args'];

				assert_same( 'array', $args['type'] );
				assert_same( false, $args['show_in_rest'] );
			}
		);
	}
);

describe(
	'Settings_Screen — the four tabs',
	function () {
		it(
			'gives every tab a section, and every setting a field on its own tab',
			function () {
				slosm_settings_screen()->add_fields();

				foreach ( Settings::TABS as $tab => $keys ) {
					$page = Settings_Screen::page_for( $tab );

					assert_true(
						isset( $GLOBALS['slosm_stub']['settings_sections'][ $page ] ),
						$tab . ' has no section'
					);

					$fields = $GLOBALS['slosm_stub']['settings_field_list'][ $page ] ?? array();
					$names  = array();

					foreach ( $fields as $in_section ) {
						foreach ( $in_section as $field ) {
							$names[] = $field['id'];
						}
					}

					assert_same( $keys, $names, $tab . ' prints the wrong fields' );
				}
			}
		);

		it(
			'prints the four tab links, and marks the one being looked at',
			function () {
				$html = slosm_settings_render( 'search' );

				foreach ( array_keys( Settings::TABS ) as $tab ) {
					assert_contains( Settings::TAB_ARG . '=' . $tab, $html );
				}

				assert_contains( 'aria-current="page"', $html );
				assert_same( 1, substr_count( $html, 'aria-current="page"' ) );

				// esc_url()'s display context, which is what tells it from
				// esc_attr() and from nothing at all: '&' becomes '&#038;'
				// (wp-includes/formatting.php, clean_url()). The same assertion
				// tests/test-locations-list.php makes about the view link, and
				// for the same reason — the url is built out of admin_url(),
				// which reads the siteurl option, which is a string a site owner
				// controls.
				assert_contains( 'page=' . Settings::PAGE . '&#038;' . Settings::TAB_ARG . '=', $html );
			}
		);

		it(
			'shows the map tab when no tab was asked for, and refuses a tab it has not got',
			function () {
				assert_same( 'map', Settings_Screen::current_tab() );

				$_GET[ Settings::TAB_ARG ] = 'billing';
				assert_same( 'map', Settings_Screen::current_tab() );

				$_GET[ Settings::TAB_ARG ] = 'advanced';
				assert_same( 'advanced', Settings_Screen::current_tab() );

				unset( $_GET[ Settings::TAB_ARG ] );
			}
		);

		it(
			'prints the fields of the tab being looked at and no others',
			function () {
				$html = slosm_settings_render( 'search' );

				assert_contains( 'name="' . Settings::OPTION . '[units]"', $html );
				assert_false(
					false !== strpos( $html, 'name="' . Settings::OPTION . '[tile_url]"' ),
					'the map tab leaked into the search tab'
				);
			}
		);
	}
);

describe(
	'Settings_Screen — the form',
	function () {
		it(
			'posts to options.php, which is what carries the Settings API save',
			function () {
				$html = slosm_settings_render();

				assert_contains( 'action="options.php"', $html );
				assert_contains( 'method="post"', $html );
			}
		);

		it(
			'carries the nonce and the option page options.php will check',
			function () {
				$html = slosm_settings_render();

				// settings_fields() prints these three; options.php reads the
				// first and passes the group to check_admin_referer().
				assert_contains( "name='option_page' value='" . Settings::GROUP . "'", $html );
				assert_contains( 'name="action" value="update"', $html );
				assert_contains( 'value="' . wp_create_nonce( Settings::GROUP . '-options' ) . '"', $html );
			}
		);

		it(
			'refuses to render for a user without the capability',
			function () {
				$screen = slosm_settings_screen();
				$screen->add_fields();

				$died = false;

				try {
					ob_start();
					$screen->render();
					ob_end_clean();
				} catch ( Slosm_Died $e ) {
					ob_end_clean();
					$died = true;
				}

				assert_true( $died, 'a user with no capability rendered the screen' );

				// The control: the same screen, the same call, one capability.
				assert_contains( 'action="options.php"', slosm_settings_render() );
			}
		);

		it(
			'asks about the capability it registered the page with',
			function () {
				slosm_settings_render();

				$asked = array();

				foreach ( $GLOBALS['slosm_stub']['cap_checks'] as $check ) {
					$asked[] = $check['capability'];
				}

				assert_true( in_array( Settings_Screen::CAPABILITY, $asked, true ) );
			}
		);

		it(
			'prints nothing at all for a field name that is not on the closed list',
			function () {
				$GLOBALS['slosm_stub']['capabilities'][ Settings_Screen::CAPABILITY ] = true;

				$screen = slosm_settings_screen();

				ob_start();
				$screen->render_field( array( 'key' => 'wp_capability' ) );
				$screen->render_field( array() );
				$stray = (string) ob_get_clean();

				assert_same( '', $stray );

				// The control: the same method with a name that is on the list
				// prints a control.
				ob_start();
				$screen->render_field( array( 'key' => 'units' ) );
				$real = (string) ob_get_clean();

				assert_contains( 'name="' . Settings::OPTION . '[units]"', $real );
			}
		);

		it(
			'prints a stored value into its field, escaped',
			function () {
				$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = array(
					'tile_attribution' => 'Tiles "by" <Example> & Co',
				);

				$html = slosm_settings_render( 'map' );

				// `<Example>` is gone rather than escaped: the value is read
				// back through Settings::get(), so sanitize_text_field() has
				// already taken the tag out — wp_strip_all_tags() does not care
				// that there is no such element. The quotes and the ampersand
				// are what esc_attr() is doing, and they are what would
				// otherwise end the attribute.
				assert_contains( 'value="Tiles &quot;by&quot; &amp; Co"', $html );
				assert_false( false !== strpos( $html, '<Example>' ), 'an angle bracket reached the page' );
			}
		);

		it(
			'gives every checkbox a hidden companion, or an unticked box could never be saved',
			function () {
				$html = slosm_settings_render( 'search' );

				assert_contains(
					'<input type="hidden" name="' . Settings::OPTION . '[near_me]" value="0" />',
					$html
				);
				assert_contains( 'type="checkbox" id="slosm-near-me" name="' . Settings::OPTION . '[near_me]" value="1"', $html );

				// The hidden field is printed first, so a browser sends 0 and
				// then — only when the box is ticked — 1, and PHP keeps the last
				// value for a repeated name. The other order would make every
				// box unticked on every save.
				assert_true(
					strpos( $html, '[near_me]" value="0"' ) < strpos( $html, '[near_me]" value="1"' ),
					'the companion must come first or the checkbox can never be on'
				);
			}
		);

		it(
			'ticks a checkbox that is on and leaves one that is off',
			function () {
				$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = array(
					'near_me'      => true,
					'autocomplete' => false,
				);

				$html = slosm_settings_render( 'search' );

				$near = substr( $html, (int) strpos( $html, '[near_me]" value="1"' ), 80 );
				$auto = substr( $html, (int) strpos( $html, '[autocomplete]" value="1"' ), 80 );

				assert_contains( "checked='checked'", $near );
				assert_false( false !== strpos( $auto, "checked='checked'" ), 'an off switch was ticked' );
			}
		);

		it(
			'offers the fields a result row can show as a group of boxes with the list’s name',
			function () {
				$html = slosm_settings_render( 'results' );

				foreach ( Settings::RESULT_FIELDS as $field ) {
					assert_contains(
						'name="' . Settings::OPTION . '[result_fields][]" value="' . $field . '"',
						$html
					);
				}
			}
		);

		it(
			'writes a number list back as the text somebody would type',
			function () {
				$GLOBALS['slosm_stub']['options'][ Settings::OPTION ] = array(
					'radius_choices' => array( 5.0, 12.5, 100.0 ),
				);

				assert_contains( 'value="5, 12.5, 100"', slosm_settings_render( 'search' ) );
			}
		);

		it(
			'says the settings were saved when options.php has just said so',
			function () {
				$_GET['settings-updated'] = 'true';

				$html = slosm_settings_render();

				unset( $_GET['settings-updated'] );

				assert_contains( 'notice-success', $html );
			}
		);
	}
);

describe(
	'Settings_Screen — clearing the cache',
	function () {
		it(
			'is offered on the advanced tab and on no other',
			function () {
				assert_contains( Settings_Screen::CLEAR_ACTION, slosm_settings_render( 'advanced' ) );
				assert_false(
					false !== strpos( slosm_settings_render( 'map' ), Settings_Screen::CLEAR_ACTION ),
					'the button is on the wrong tab'
				);
			}
		);

		it(
			'posts to admin-post.php with a nonce of its own, in a form of its own',
			function () {
				$html = slosm_settings_render( 'advanced' );

				assert_contains( 'admin-post.php', $html );
				assert_contains( 'name="action" value="' . Settings_Screen::CLEAR_ACTION . '"', $html );
				assert_contains( 'value="' . wp_create_nonce( Settings_Screen::CLEAR_ACTION ) . '"', $html );

				// The field name as a literal, not through the constant. A
				// fixture that read the constant would follow it anywhere it was
				// moved — including to _wpnonce, which is the name every other
				// form posting to admin-post.php also uses. The same reason
				// tests/test-bulk-geocode.php spells 'nonce:bulk-posts' out.
				assert_contains( 'name="slosm_clear_nonce"', $html );
				assert_same( 'slosm_clear_nonce', Settings_Screen::NONCE_FIELD );
			}
		);

		it(
			'moves both generations, so every cached payload and every cached lookup is unreachable',
			function () {
				$GLOBALS['slosm_stub']['capabilities'][ Settings_Screen::CAPABILITY ] = true;
				$_POST[ Settings_Screen::NONCE_FIELD ] = wp_create_nonce( Settings_Screen::CLEAR_ACTION );

				$repository = new Store_Repository();
				$geocoder   = new Geocoder();

				$before_payload = $repository->cache_key();
				$before_lookup  = $geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Krucza 1, Warszawa' );

				slosm_settings_screen()->handle_clear_cache();

				unset( $_POST[ Settings_Screen::NONCE_FIELD ] );

				assert_false( $before_payload === $repository->cache_key(), 'the payload key did not move' );
				assert_false(
					$before_lookup === $geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Krucza 1, Warszawa' ),
					'the lookup key did not move'
				);
			}
		);

		it(
			'clears the map payload even when something earlier in the request already flushed',
			function () {
				$GLOBALS['slosm_stub']['capabilities'][ Settings_Screen::CAPABILITY ] = true;
				$_POST[ Settings_Screen::NONCE_FIELD ] = wp_create_nonce( Settings_Screen::CLEAR_ACTION );

				// The separating input for the forced flush, and the ordinary
				// state on a real request: Plugin::boot() hands this screen the
				// same Store_Repository as the six invalidation hooks, and its
				// once-per-request guard exists so that one post save does not
				// spend three generations. A guard tripped by something earlier
				// would make the button do nothing and report success.
				$repository = new Store_Repository();
				$repository->flush_cache();

				$before = $repository->cache_key();

				$screen = new Settings_Screen(
					$repository,
					new Geocoder(),
					static function (): void {}
				);

				$screen->handle_clear_cache();

				unset( $_POST[ Settings_Screen::NONCE_FIELD ] );

				assert_false( $before === $repository->cache_key(), 'the guard swallowed the button' );
			}
		);

		it(
			'leaves the cached entries themselves exactly where they are, which is why it works behind Redis',
			function () {
				$GLOBALS['slosm_stub']['capabilities'][ Settings_Screen::CAPABILITY ] = true;
				$_POST[ Settings_Screen::NONCE_FIELD ] = wp_create_nonce( Settings_Screen::CLEAR_ACTION );

				$repository = new Store_Repository();
				$key        = $repository->cache_key();

				set_transient( $key, array( 'a payload' ), HOUR_IN_SECONDS );

				slosm_settings_screen()->handle_clear_cache();

				unset( $_POST[ Settings_Screen::NONCE_FIELD ] );

				// Nothing was enumerated and nothing was deleted: the entry is
				// still there, and simply cannot be reached any more.
				assert_true( array_key_exists( $key, $GLOBALS['slosm_stub']['transients'] ) );
				assert_false( $key === $repository->cache_key() );
			}
		);

		it(
			'also forgets the addresses a lookup has already failed on',
			function () {
				$GLOBALS['slosm_stub']['capabilities'][ Settings_Screen::CAPABILITY ] = true;
				$_POST[ Settings_Screen::NONCE_FIELD ] = wp_create_nonce( Settings_Screen::CLEAR_ACTION );

				$before = Admin::failure_key( 'Nigdzie 1' );

				slosm_settings_screen()->handle_clear_cache();

				unset( $_POST[ Settings_Screen::NONCE_FIELD ] );

				assert_false( $before === Admin::failure_key( 'Nigdzie 1' ) );
			}
		);

		it(
			'sends the person back to the tab the button is on, with something to read',
			function () {
				$GLOBALS['slosm_stub']['capabilities'][ Settings_Screen::CAPABILITY ] = true;
				$_POST[ Settings_Screen::NONCE_FIELD ] = wp_create_nonce( Settings_Screen::CLEAR_ACTION );

				slosm_settings_screen()->handle_clear_cache();

				unset( $_POST[ Settings_Screen::NONCE_FIELD ] );

				$redirects = $GLOBALS['slosm_stub']['redirects'];

				assert_same( 1, count( $redirects ) );
				assert_contains( Settings::TAB_ARG . '=advanced', $redirects[0]['location'] );
				assert_contains( Settings_Screen::CLEARED_ARG, $redirects[0]['location'] );

				// And it stops the request rather than falling through into
				// whatever admin-post.php would print next.
				assert_true( ! empty( $GLOBALS['slosm_stub']['left'] ) );
			}
		);

		it(
			'refuses a request with no nonce, and clears nothing',
			function () {
				$GLOBALS['slosm_stub']['capabilities'][ Settings_Screen::CAPABILITY ] = true;

				$before = ( new Store_Repository() )->cache_key();
				$died   = false;

				try {
					slosm_settings_screen()->handle_clear_cache();
				} catch ( Slosm_Died $e ) {
					$died = true;
				}

				assert_true( $died, 'a request with no nonce was not refused' );
				assert_same( $before, ( new Store_Repository() )->cache_key() );
				assert_same( array(), $GLOBALS['slosm_stub']['redirects'] );
			}
		);

		it(
			'refuses a request whose nonce is for something else',
			function () {
				$GLOBALS['slosm_stub']['capabilities'][ Settings_Screen::CAPABILITY ] = true;
				$_POST[ Settings_Screen::NONCE_FIELD ] = wp_create_nonce( 'some-other-action' );

				$before = ( new Store_Repository() )->cache_key();
				$died   = false;

				try {
					slosm_settings_screen()->handle_clear_cache();
				} catch ( Slosm_Died $e ) {
					$died = true;
				}

				unset( $_POST[ Settings_Screen::NONCE_FIELD ] );

				assert_true( $died );
				assert_same( $before, ( new Store_Repository() )->cache_key() );
			}
		);

		it(
			'refuses a user who may not manage options, even with a good nonce',
			function () {
				$_POST[ Settings_Screen::NONCE_FIELD ] = wp_create_nonce( Settings_Screen::CLEAR_ACTION );

				$before = ( new Store_Repository() )->cache_key();
				$died   = false;

				try {
					slosm_settings_screen()->handle_clear_cache();
				} catch ( Slosm_Died $e ) {
					$died = true;
				}

				unset( $_POST[ Settings_Screen::NONCE_FIELD ] );

				assert_true( $died, 'a user without manage_options cleared the cache' );
				assert_same( $before, ( new Store_Repository() )->cache_key() );
			}
		);

		it(
			'takes a nonce from the tick before, because a settings page is left open',
			function () {
				$GLOBALS['slosm_stub']['capabilities'][ Settings_Screen::CAPABILITY ] = true;

				// The stub's second live answer, standing for a form opened
				// overnight; wp_verify_nonce() answers 2 rather than 1 and core
				// accepts it. See tests/bootstrap.php.
				$_POST[ Settings_Screen::NONCE_FIELD ] = 'stale:' . Settings_Screen::CLEAR_ACTION;

				$before = ( new Store_Repository() )->cache_key();

				slosm_settings_screen()->handle_clear_cache();

				unset( $_POST[ Settings_Screen::NONCE_FIELD ] );

				assert_false( $before === ( new Store_Repository() )->cache_key() );
			}
		);

		it(
			'says so on the far side of the redirect',
			function () {
				$_GET[ Settings_Screen::CLEARED_ARG ] = '1';

				$html = slosm_settings_render( 'advanced' );

				unset( $_GET[ Settings_Screen::CLEARED_ARG ] );

				assert_contains( 'notice-success', $html );
			}
		);
	}
);

describe(
	'Admin::failure_key() — narrowed with whatever narrows the lookup',
	function () {
		it(
			'gives one address under two country restrictions two keys',
			function () {
				assert_false(
					Admin::failure_key( 'Krucza 1', 'pl' ) === Admin::failure_key( 'Krucza 1', 'de' ),
					'a failure under one restriction would suppress a lookup under another'
				);
			}
		);

		it(
			'gives the same address under the same restriction one key',
			function () {
				assert_same( Admin::failure_key( 'Krucza 1', 'pl' ), Admin::failure_key( 'Krucza 1', 'pl' ) );
			}
		);

		it(
			'gives two addresses two keys, restriction or not',
			function () {
				assert_false( Admin::failure_key( 'Krucza 1' ) === Admin::failure_key( 'Krucza 2' ) );
			}
		);

		it(
			'moves with the geocode generation, so clearing the cache forgets the failures too',
			function () {
				$before = Admin::failure_key( 'Krucza 1' );

				( new Geocoder() )->flush_cache();

				assert_false( $before === Admin::failure_key( 'Krucza 1' ) );
			}
		);
	}
);

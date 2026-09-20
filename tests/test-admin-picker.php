<?php
/**
 * Task 18: the map under the coordinate fields, and the message an editor
 * never got to read.
 *
 * Two subjects, and they are one task because they are one screen.
 *
 * The picker
 * ----------
 * Everything about the map itself is in assets/js/admin.js and is covered by
 * tests/js/admin-picker.test.js, which is where a DOM exists. What is PHP's
 * and therefore here: the script and Leaflet reach that one screen and no
 * other, the box prints the container the script binds to, the config in that
 * container says what the server will enforce, and — the part that cannot be
 * proved in JavaScript at all — what the save then does with what the picker
 * wrote.
 *
 * That last one is the point of the whole feature and is why several cases
 * below post coordinates through Admin::save() rather than asserting about
 * markup. "Dragging a pin locks the location" is not a claim about a drag
 * handler; it is a claim about what happens two seconds later, on the server,
 * to the values the drag handler left in two inputs. The drag half is proved
 * in JavaScript, the save half is proved here, and the two meet at a format:
 * the JavaScript writes a coordinate rounded to the same number of decimals
 * Admin::COORDINATE_DECIMALS says, which is what keeps a drag that lands back
 * on the stored point from reading as a change. A case here asserts the config
 * carries that number, and a case in the JavaScript asserts the script uses
 * it, so neither file is believed on its own.
 *
 * Why every scoping case carries its opposite
 * -------------------------------------------
 * The same rule tests/test-assets.php states. "The script did not load on the
 * posts screen" is the expected result of correct scoping and of an
 * enqueue_admin() that is empty, so the negative and the positive are asserted
 * in one case against one boot.
 *
 * The message channel
 * -------------------
 * Admin's class docblock traces, through WordPress 6.9.1's own source, why a
 * message set on a block-editor save is read and deleted by a render nobody
 * sees. Task 18 adds the two facts that decide what can be done about it:
 *
 * - redirect_post() builds its location from get_edit_post_link( $post_id,
 *   'url' ) — wp-admin/includes/post.php line 2215 and line 2225 — so the
 *   followed GET carries `message=N` and does NOT carry `meta-box-loader`.
 *   The discarded render cannot recognise itself, which is why this plugin
 *   marks it: the redirect_post_location filter fires on the same request that
 *   still has meta-box-loader in its query string, and that is the one moment
 *   the two facts are both available.
 * - Nothing reads the response. edit-post.js is
 *   `await apiFetch({url:window._wpMetaBoxUrl,method:"POST",body:formData,
 *   parse:false}); dispatch.metaBoxUpdatesSuccess()`, and the catch dispatches
 *   metaBoxUpdatesFailure — both of which are one reducer case setting
 *   isSaving to false (wp-includes/js/dist/edit-post.js lines 455-461 and
 *   632-687). So a failed metabox save is as silent as a successful one, and
 *   there is no body, header or status a server could answer with that the
 *   block editor would show anybody.
 *
 * What follows from those two is the whole of what this task claims: the
 * discarded render leaves the message alone, so the next real page load shows
 * it. It does not claim the editor is told at the moment of the save — the
 * cases below cannot prove that and neither can anything else, because the
 * only remaining path is a read endpoint this task does not build.
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
require_once dirname( __DIR__ ) . '/admin/class-settings-screen.php';
require_once dirname( __DIR__ ) . '/admin/class-shortcode-generator.php';
require_once dirname( __DIR__ ) . '/admin/class-admin.php';
require_once dirname( __DIR__ ) . '/admin/class-locations-list.php';
require_once dirname( __DIR__ ) . '/admin/class-bulk-geocode.php';

use Asymetria\StoreLocator\Admin\Admin;
use Asymetria\StoreLocator\Assets;
use Asymetria\StoreLocator\Plugin;
use Asymetria\StoreLocator\Post_Type;
use Asymetria\StoreLocator\Rest_Controller;
use Asymetria\StoreLocator\Settings;
use Asymetria\StoreLocator\Store_Repository;

if ( ! defined( 'SLOSM_PICKER_POST_ID' ) ) {
	/**
	 * The location every case here edits.
	 */
	define( 'SLOSM_PICKER_POST_ID', 11 );
}

if ( ! function_exists( 'slosm_picker_boot' ) ) {
	/**
	 * A freshly booted plugin, standing for one request.
	 *
	 * A fresh instance rather than Plugin::instance(), for the reason
	 * tests/test-assets.php gives: the singleton boots at most once per
	 * process, so every case after the first would hook nothing at all —
	 * which is the state every absence assertion here would pass in.
	 *
	 * @return Plugin
	 */
	function slosm_picker_boot(): Plugin {
		$construct = Closure::bind(
			static function () {
				return new Plugin();
			},
			null,
			Plugin::class
		);

		$plugin = $construct();
		$plugin->boot();

		return $plugin;
	}
}

if ( ! function_exists( 'slosm_picker_admin_request' ) ) {
	/**
	 * One admin request, up to the point the metaboxes are about to render.
	 *
	 * init fires — which is where the front-end handles are registered, and it
	 * fires in the admin too — then admin_enqueue_scripts with the hook suffix
	 * WordPress would pass. $current_screen is staged first, because
	 * wp-admin/admin-header.php sets it above the do_action (it prints
	 * `pagenow` and `typenow` from it at lines 105-106 and fires the hook at
	 * line 123).
	 *
	 * Stub state is written, so this may only be called from inside it().
	 *
	 * @param string $hook_suffix Admin page, as WordPress names it.
	 * @param string $base        Screen base: 'post' for an edit form, 'edit' for a list table.
	 * @param string $post_type   Post type the screen is about.
	 * @return void
	 */
	function slosm_picker_admin_request( string $hook_suffix, string $base, string $post_type ): void {
		slosm_picker_boot();

		do_action( 'init' );

		slosm_stub_screen( $base, $post_type );

		do_action( 'admin_enqueue_scripts', $hook_suffix );
	}
}

if ( ! function_exists( 'slosm_picker_post' ) ) {
	/**
	 * One location row, as save_post hands it over.
	 *
	 * @param int $id Post id.
	 * @return object
	 */
	function slosm_picker_post( int $id = SLOSM_PICKER_POST_ID ): object {
		$post = (object) array(
			'ID'          => $id,
			'post_type'   => Post_Type::POST_TYPE,
			'post_title'  => 'Warszawa',
			'post_status' => 'publish',
		);

		$GLOBALS['slosm_stub']['posts_by_id'][ $id ] = $post;

		return $post;
	}
}

if ( ! function_exists( 'slosm_picker_stage' ) ) {
	/**
	 * Puts a location in storage, through the repository's own mapping.
	 *
	 * @param array $fields Field values, keyed by field name.
	 * @param int   $id     Post id.
	 * @return void
	 */
	function slosm_picker_stage( array $fields, int $id = SLOSM_PICKER_POST_ID ): void {
		foreach ( $fields as $field => $value ) {
			update_post_meta( $id, Store_Repository::META_KEYS[ $field ], $value );
		}
	}
}

if ( ! function_exists( 'slosm_picker_save' ) ) {
	/**
	 * Fills $_POST the way the browser would and runs the save handler.
	 *
	 * The same shape tests/test-location-metabox.php uses, slashes and all,
	 * kept here rather than shared because run.php loads these files in glob
	 * order and this one is loaded first.
	 *
	 * @param array $fields Submitted field values, unslashed.
	 * @param int   $id     Post id.
	 * @return array The location as the save left it.
	 */
	function slosm_picker_save( array $fields, int $id = SLOSM_PICKER_POST_ID ): array {
		$GLOBALS['slosm_stub']['current_user_id']          = 1;
		$GLOBALS['slosm_stub']['capabilities']['edit_post'] = true;

		$post = slosm_picker_post( $id );

		$_POST = array();

		foreach ( $fields as $field => $value ) {
			$_POST[ Admin::FIELD_PREFIX . $field ] = wp_slash( $value );
		}

		$_POST[ Admin::NONCE_FIELD ] = 'nonce:' . Admin::NONCE_ACTION . $id;

		try {
			$admin = new Admin( new Store_Repository() );

			$admin->save( $id, $post );
		} finally {
			$_POST = array();
		}

		$stored = array();

		foreach ( Store_Repository::META_KEYS as $field => $key ) {
			$stored[ $field ] = get_post_meta( $id, $key, true );
		}

		return $stored;
	}
}

if ( ! function_exists( 'slosm_picker_render' ) ) {
	/**
	 * The metabox, rendered for one location.
	 *
	 * @param int   $id    Post id.
	 * @param array $query Query arguments the render should see, as $_GET.
	 * @return string
	 */
	function slosm_picker_render( int $id = SLOSM_PICKER_POST_ID, array $query = array() ): string {
		$post = slosm_picker_post( $id );

		$_GET = $query;

		try {
			$admin = new Admin( new Store_Repository() );

			ob_start();
			$admin->render( $post );

			return (string) ob_get_clean();
		} finally {
			$_GET = array();
		}
	}
}

if ( ! function_exists( 'slosm_picker_config_from_html' ) ) {
	/**
	 * The picker config a browser would read off the rendered box.
	 *
	 * Cut at the first double quote the way a browser's tokeniser ends an
	 * attribute, then entity-decoded and json-decoded, in that order.
	 *
	 * @param string $html Rendered markup.
	 * @return mixed Decoded config, or null.
	 */
	function slosm_picker_config_from_html( string $html ) {
		$needle = ' data-slosm-picker="';
		$open   = strpos( $html, $needle );

		if ( false === $open ) {
			return null;
		}

		$start = $open + strlen( $needle );
		$end   = strpos( $html, '"', $start );

		if ( false === $end ) {
			return null;
		}

		return json_decode( html_entity_decode( substr( $html, $start, $end - $start ), ENT_QUOTES, 'UTF-8' ), true );
	}
}

describe(
	'where the picker loads',
	function () {

		it(
			'loads on the location edit screen and on no other',
			function () {
				slosm_picker_admin_request( 'post.php', 'post', Post_Type::POST_TYPE );

				// The positive half first, because everything below it is an
				// absence and an absence is what an empty method produces.
				assert_true(
					wp_script_is( Assets::SCRIPT_ADMIN, 'enqueued' ),
					'the picker script never reached the location edit screen'
				);
				assert_true(
					wp_script_is( Assets::SCRIPT_LEAFLET, 'enqueued' ),
					'Leaflet never reached the location edit screen'
				);
				assert_true(
					wp_style_is( Assets::STYLE_LEAFLET, 'enqueued' ),
					"Leaflet's stylesheet never reached the location edit screen"
				);

				$queued = $GLOBALS['slosm_stub']['script_queue'];

				// Four other screens, each a real one, each with a different
				// reason to be tempting.
				foreach ( array(
					// The list table: same post type, no metabox on it.
					array( 'edit.php', 'edit', Post_Type::POST_TYPE ),
					// Another post type's edit form: same screen shape.
					array( 'post.php', 'post', 'post' ),
					array( 'post-new.php', 'post', 'page' ),
					// A settings page, which is where Task 21 will live.
					array( 'options-general.php', 'options-general', '' ),
				) as $elsewhere ) {
					slosm_stub_reset();

					slosm_picker_admin_request( $elsewhere[0], $elsewhere[1], $elsewhere[2] );

					assert_false(
						wp_script_is( Assets::SCRIPT_ADMIN, 'enqueued' ),
						'the picker script loaded on ' . $elsewhere[0] . ' for ' . $elsewhere[2]
					);
					assert_false(
						wp_script_is( Assets::SCRIPT_LEAFLET, 'enqueued' ),
						'Leaflet loaded on ' . $elsewhere[0] . ' for ' . $elsewhere[2]
					);
					assert_false(
						wp_style_is( Assets::STYLE_LEAFLET, 'enqueued' ),
						"Leaflet's stylesheet loaded on " . $elsewhere[0] . ' for ' . $elsewhere[2]
					);
				}

				// And the queue on the screen that does want it was not empty,
				// which is what makes the four assertions above mean anything.
				assert_true( in_array( Assets::SCRIPT_ADMIN, $queued, true ) );
			}
		);

		it(
			'wants both the screen and the hook suffix, because neither alone is the edit form',
			function () {
				// post-new.php is the other half of the edit form and has to
				// work: a location gets its coordinates when it is created far
				// more often than afterwards.
				slosm_picker_admin_request( 'post-new.php', 'post', Post_Type::POST_TYPE );

				assert_true( wp_script_is( Assets::SCRIPT_ADMIN, 'enqueued' ) );

				slosm_stub_reset();

				// A screen object that is not there at all. get_current_screen()
				// really returns null before set_current_screen() has run, and
				// reading ->post_type off null is a fatal inside an admin
				// request rather than a missing map.
				slosm_picker_boot();
				do_action( 'init' );
				do_action( 'admin_enqueue_scripts', 'post.php' );

				assert_false( wp_script_is( Assets::SCRIPT_ADMIN, 'enqueued' ) );
				assert_same( array(), $GLOBALS['slosm_stub']['doing_it_wrong'] );
			}
		);

		it(
			'returns rather than fatally on a request with no admin screen loaded',
			function () {
				// A child process, because a function cannot be un-defined
				// inside a running one and bootstrap.php defines this one for
				// every other case in the suite. The case it stands for is real:
				// a page builder or an optimiser firing admin_enqueue_scripts
				// from a front-end request, where
				// wp-admin/includes/screen.php has never been loaded. An
				// undefined function there is a fatal in an enqueue hook, which
				// takes the whole page with it.
				//
				// No bootstrap.php in the child on purpose: it is what defines
				// get_current_screen(), so loading it would be loading the very
				// thing this case is about not having.
				$root  = dirname( __DIR__ );
				$child = '<?php' . "
"
					. 'define( ' . var_export( 'ABSPATH', true ) . ', ' . var_export( $root . '/', true ) . ' );' . "
"
					. 'require ' . var_export( $root . '/includes/class-assets.php', true ) . ';' . "
"
					. '( new \Asymetria\StoreLocator\Assets() )->enqueue_admin( ' . var_export( 'post.php', true ) . ' );' . "
"
					. 'echo ' . var_export( 'RETURNED', true ) . ';' . "
";

				// No .php suffix: the CLI binary runs a file whatever it is
				// called, and appending one would orphan the file tempnam()
				// created.
				$script  = tempnam( sys_get_temp_dir(), 'slosm' );
				$command = escapeshellarg( PHP_BINARY )
					. ' -d display_errors=1 -d error_reporting=-1 '
					. escapeshellarg( $script ) . ' 2>&1';

				file_put_contents( $script, $child );

				try {
					$output = (string) shell_exec( $command );
				} finally {
					unlink( $script );
				}

				assert_contains( 'RETURNED', $output );
				assert_false(
					false !== stripos( $output, 'Fatal error' ),
					'enqueue_admin() fataled on a request with no admin screen: ' . $output
				);
			}
		);

		it(
			'registers nothing of its own on a front-end request',
			function () {
				slosm_picker_boot();

				do_action( 'init' );

				// The seven front-end handles are registered on init for every
				// request on the site, and this is deliberately not an eighth.
				// Nothing but one admin screen can ever ask for it, so it is
				// registered where it is enqueued — which is also what keeps
				// tests/test-assets.php's "seven handles and no others" true.
				assert_false( wp_script_is( Assets::SCRIPT_ADMIN, 'registered' ) );

				// Two controls, because without them this case is satisfied by
				// an Assets that has never heard of the handle at all. The
				// first says init ran; the second says the handle exists
				// somewhere, on the screen that wants it.
				assert_true( wp_script_is( Assets::SCRIPT_LOCATOR, 'registered' ) );

				slosm_stub_reset();
				slosm_picker_admin_request( 'post.php', 'post', Post_Type::POST_TYPE );

				assert_true(
					wp_script_is( Assets::SCRIPT_ADMIN, 'registered' ),
					'the picker handle is registered nowhere at all'
				);
			}
		);

		it(
			'declares Leaflet and, since Task 21, not the whole front-end locator',
			function () {
				slosm_picker_admin_request( 'post.php', 'post', Post_Type::POST_TYPE );

				$script = $GLOBALS['slosm_stub']['scripts'][ Assets::SCRIPT_ADMIN ] ?? array();

				// This used to be array( SCRIPT_LEAFLET, SCRIPT_LOCATOR ), and
				// the locator was there for one reason: admin.js read the frozen
				// TILE constant out of window.SLOSM rather than carrying a
				// second copy of a line the ODbL requires to be on the map. That
				// cost 150 KB of unminified front-end JavaScript on an edit
				// screen, and assets/js/admin.js recorded the cost and named
				// Task 21 as where it should be retired deliberately.
				//
				// It is retired because the reason went, not because the cost
				// was re-weighed: the tile url is a setting now, so it has to be
				// localised to this screen anyway, and Admin::picker_config()
				// carries it. There is still exactly one place the attribution
				// is written — Settings, rather than locator.js.
				assert_same(
					array( Assets::SCRIPT_LEAFLET ),
					$script['deps'] ?? array(),
					'the picker must declare Leaflet, and must not drag the whole locator onto the edit screen'
				);

				// The version is the plugin's, never false: false means "use
				// the WordPress version", so the query string would move when
				// WordPress is updated and stand still when this plugin is.
				assert_same( SLOSM_VERSION, $script['ver'] ?? null );
				assert_same( Assets::SRC_ADMIN, str_replace( SLOSM_URL, '', (string) ( $script['src'] ?? '' ) ) );

				// Footer and deferred, the same arrangement the locator has and
				// for the same reason: the script binds to markup the metabox
				// prints, so it must not run above it.
				assert_same( 1, $script['extra']['group'] ?? null );
				assert_same( 'defer', $script['extra']['strategy'] ?? null );
			}
		);

		it(
			'localises the admin strings once, to an object of their own',
			function () {
				slosm_picker_admin_request( 'post.php', 'post', Post_Type::POST_TYPE );

				$calls = array();

				foreach ( $GLOBALS['slosm_stub']['localize_calls'] as $call ) {
					if ( Assets::SCRIPT_ADMIN === $call['handle'] ) {
						$calls[] = $call;
					}
				}

				// A second firing of the hook, which is not hypothetical: core
				// fires admin_enqueue_scripts again from iframe_header()
				// (wp-admin/includes/template.php line 2154) and from
				// media_upload_header() with 'media-upload-popup'. Two calls
				// would be two `var slosmAdminL10n = {…}` statements in the
				// page, because WP_Scripts::localize() concatenates onto what a
				// handle already carries rather than replacing it.
				do_action( 'admin_enqueue_scripts', 'post.php' );

				$calls = array();

				foreach ( $GLOBALS['slosm_stub']['localize_calls'] as $call ) {
					if ( Assets::SCRIPT_ADMIN === $call['handle'] ) {
						$calls[] = $call;
					}
				}

				assert_same( 1, count( $calls ), 'the admin strings were attached ' . count( $calls ) . ' times' );
				assert_same( Assets::L10N_ADMIN_OBJECT, $calls[0]['object'] );

				// Its own variable, not the front end's. Both scripts are on
				// this screen, and one payload landing on top of the other is
				// how a locator loses its strings.
				assert_false( Assets::L10N_OBJECT === Assets::L10N_ADMIN_OBJECT );

				$strings = ( new Assets() )->admin_strings();

				assert_same( $strings, $calls[0]['l10n'] );
				assert_false( array() === $strings, 'the admin script was given no strings at all' );

				foreach ( $strings as $key => $value ) {
					assert_true( is_string( $value ), $key . ' is not a string' );
					assert_false( '' === $value, $key . ' is empty' );
				}
			}
		);

		it(
			'stands down when another plugin owns the handle, and not when it owns it itself',
			function () {
				slosm_picker_boot();

				do_action( 'init' );

				// Somebody else got there first. The script under this handle is
				// a file this plugin knows nothing about: enqueuing it would put
				// a stranger on the screen in the picker's name, and the l10n
				// payload would be attached to it — which the "is the data
				// already there" guard cannot catch, because a stranger carries
				// no data under that key either.
				wp_register_script( Assets::SCRIPT_ADMIN, 'https://example.test/other-plugin/map.js', array(), '1.0', true );

				slosm_stub_screen( 'post', Post_Type::POST_TYPE );

				do_action( 'admin_enqueue_scripts', 'post.php' );

				assert_false(
					in_array( Assets::SCRIPT_ADMIN, $GLOBALS['slosm_stub']['script_queue'], true ),
					"another plugin's script was enqueued in the picker's name"
				);
				assert_same( array(), $GLOBALS['slosm_stub']['localize_calls'], 'the admin strings were hung off a foreign script' );
				assert_false( wp_style_is( Assets::STYLE_LEAFLET, 'enqueued' ) );

				// And the other half: the second firing of the hook in one
				// request also gets a false out of wp_register_script(),
				// because the first firing is what registered it. Standing down
				// on that one is harmless — everything has already happened —
				// and this says so rather than leaving it to be wondered about.
				// It is not a separating input, and the mutation doc records
				// why: a version that told the two falses apart was written,
				// and a mutation collapsing them survived the whole suite.
				slosm_stub_reset();

				slosm_picker_admin_request( 'post.php', 'post', Post_Type::POST_TYPE );

				assert_true( wp_script_is( Assets::SCRIPT_ADMIN, 'enqueued' ) );

				do_action( 'admin_enqueue_scripts', 'post.php' );

				assert_true( wp_script_is( Assets::SCRIPT_ADMIN, 'enqueued' ) );
				assert_same( 1, count( $GLOBALS['slosm_stub']['localize_calls'] ) );
			}
		);

		it(
			'is hooked from boot(), on admin_enqueue_scripts and nowhere else',
			function () {
				slosm_picker_boot();

				$hooked = array();

				foreach ( $GLOBALS['slosm_stub']['actions'] as $hook => $registrations ) {
					foreach ( $registrations as $registered ) {
						if ( is_array( $registered['callback'] )
							&& $registered['callback'][0] instanceof Assets
							&& 'enqueue_admin' === $registered['callback'][1] ) {
							$hooked[] = array( $hook, $registered['accepted_args'] );
						}
					}
				}

				assert_same( array( array( 'admin_enqueue_scripts', 1 ) ), $hooked );
			}
		);
	}
);

describe(
	'what the box prints for the picker',
	function () {

		it(
			'prints the map, the button and the lookup field, and only with the script',
			function () {
				slosm_picker_admin_request( 'post.php', 'post', Post_Type::POST_TYPE );

				$html = slosm_picker_render();

				assert_contains( 'class="slosm-metabox__picker"', $html );
				assert_contains( 'class="slosm-metabox__map"', $html );
				assert_contains( 'slosm-metabox__lookup', $html );
				assert_contains( 'name="slosm_lookup"', $html );

				// Nothing enqueued the script, so the container would be an
				// empty bordered box with a button that does nothing. A render
				// that cannot be brought to life prints no picker at all.
				slosm_stub_reset();

				$bare = slosm_picker_render();

				// The class names alone would be found in the box's own inline
				// stylesheet, which is printed either way; the markup is what
				// this is about.
				assert_false( false !== strpos( $bare, 'data-slosm-picker' ), 'the picker printed with no script to run it' );
				assert_false( false !== strpos( $bare, '<div class="slosm-metabox__map">' ), 'the map printed with no script to run it' );
				assert_false( false !== strpos( $bare, '<button' ), 'the lookup button printed with no script to run it' );
				assert_false( false !== strpos( $bare, 'name="slosm_lookup"' ), 'the lookup field printed with no script to run it' );

				// And the rest of the box is unaffected: the coordinates are
				// still typeable by hand on a screen with no JavaScript.
				assert_contains( 'name="slosm_lat"', $bare );
				assert_contains( 'name="slosm_lng"', $bare );
			}
		);

		it(
			'gives the picker the route, the ids and the limits the server enforces',
			function () {
				slosm_picker_admin_request( 'post.php', 'post', Post_Type::POST_TYPE );

				$config = slosm_picker_config_from_html( slosm_picker_render() );

				assert_true( is_array( $config ), 'the picker config did not survive the attribute' );

				assert_same( rest_url( Rest_Controller::REST_NAMESPACE . '/geocode' ), $config['geocode'] );

				assert_same( 'slosm_lat', $config['lat'] );
				assert_same( 'slosm_lng', $config['lng'] );
				assert_same( 'slosm_lookup', $config['lookup'] );

				// The address list comes from ADDRESS_FIELDS, in its order, so
				// a seventh address field is looked up rather than silently
				// left out of the query.
				$expected = array();

				foreach ( Admin::ADDRESS_FIELDS as $field ) {
					$expected[] = Admin::FIELD_PREFIX . $field;
				}

				assert_same( $expected, $config['address'] );

				// The three numbers that have to agree with the save, sent
				// rather than restated: a picker that rounded to six decimals
				// would lock a location nobody touched, and one that allowed a
				// latitude of 120 would place a pin the save then refuses.
				assert_same( Admin::COORDINATE_DECIMALS, $config['decimals'] );
				assert_same( (float) Admin::LAT_LIMIT, (float) $config['latLimit'] );
				assert_same( (float) Admin::LNG_LIMIT, (float) $config['lngLimit'] );
				assert_same( Admin::PICKER_ZOOM, $config['zoom'] );

				// A REST nonce, so the button still works on a site that has
				// closed its REST API to anonymous requests. It is the only
				// credential in here, and there is nothing else: no post id, no
				// user, and none of the location's own stored fields.
				assert_same( 'nonce:wp_rest', $config['nonce'] );

				// The tile layer, which arrived with Task 21 and is the first
				// thing in this config to come out of a stored option. The same
				// three keys the front end is handed, from the same method, so
				// the edit screen and the public map cannot end up on different
				// tile servers or under different credit lines.
				assert_same( Settings::tile_config(), $config['tile'] );
				assert_contains( '{z}', $config['tile']['url'] );

				assert_same(
					array( 'geocode', 'nonce', 'lat', 'lng', 'lookup', 'address', 'zoom', 'decimals', 'latLimit', 'lngLimit', 'tile' ),
					array_keys( $config )
				);
			}
		);

		it(
			'escapes the config into the attribute rather than trusting it',
			function () {
				slosm_picker_admin_request( 'post.php', 'post', Post_Type::POST_TYPE );

				// Nothing in this config comes from an editor today, which is
				// exactly why the escaping has to be asserted rather than
				// argued: the day something does, the argument is gone and the
				// code is unchanged. rest_url() is home_url() underneath, and
				// the home option is a string a site owner controls.
				$GLOBALS['slosm_stub']['options']['home'] = 'https://example.test/"><script>x</script>';

				$html = slosm_picker_render();

				assert_false( false !== strpos( $html, '<script>' ), 'the config broke out of its attribute' );
				assert_contains( '&quot;', $html );

				$config = slosm_picker_config_from_html( $html );

				assert_true( is_array( $config ) );
				assert_contains( '"><script>x</script>', (string) $config['geocode'] );
			}
		);

		it(
			'renders the lookup field empty, whatever the last save did',
			function () {
				slosm_picker_admin_request( 'post.php', 'post', Post_Type::POST_TYPE );

				slosm_picker_stage(
					array(
						'lat'        => '52.2297',
						'lng'        => '21.0122',
						'lat_locked' => '1',
					)
				);

				$html = slosm_picker_render();

				// A lookup is something that happened in a browser, once. A
				// value carried back into the form would tell the next save
				// that a lookup it never made had placed the pin, which is the
				// one thing that silently unlocks a hand-placed location.
				assert_contains( 'name="slosm_lookup" value=""', $html );

				// The control: the pair beside it did come back.
				assert_contains( 'value="52.2297"', $html );
			}
		);

		it(
			'reads the lookup field from the form and never stores it',
			function () {
				// It is in the list the save reads and in none of the lists the
				// save writes, and both halves matter: read, or the button
				// cannot report anything; not written, because
				// Store_Repository has no meta key for it and inventing one
				// would put a browser's scratch value in the database for ever.
				assert_false(
					array_key_exists( 'lookup', Store_Repository::META_KEYS ),
					'the lookup field has a meta key, so it is being stored'
				);

				foreach ( array( Admin::ADDRESS_FIELDS, Admin::CONTACT_FIELDS, Admin::HOURS_FIELDS, Admin::COORDINATE_FIELDS ) as $group ) {
					assert_false( in_array( 'lookup', $group, true ), 'the lookup field is in a group the save stores' );
				}

				assert_same( array( 'lookup' ), Admin::LOOKUP_FIELDS );

				slosm_picker_stage(
					array(
						'lat'        => '52.2297',
						'lng'        => '21.0122',
						'lat_locked' => '1',
					)
				);

				$stored = slosm_picker_save(
					array(
						'lat'    => '52.4064',
						'lng'    => '16.9252',
						'lookup' => '52.4064,16.9252',
					)
				);

				// Nothing under the obvious key, nor under any other: the whole
				// meta table is checked, because "not stored where I looked" is
				// what a typo'd key also produces.
				foreach ( $GLOBALS['slosm_stub']['post_meta'][ SLOSM_PICKER_POST_ID ] ?? array() as $key => $value ) {
					assert_false(
						false !== strpos( (string) $value, '52.4064,16.9252' ),
						'the lookup value was stored under ' . $key
					);
				}

				// The control, and it is what makes the absence mean something:
				// the value was *read*. A save that never looked at the field
				// would have locked this location.
				assert_same( '', $stored['lat_locked'], 'the lookup field never reached the save' );
				assert_same( '52.4064', $stored['lat'] );
			}
		);
	}
);

describe(
	'what the picker leaves for the save',
	function () {

		it(
			'locks the location when a dragged pin differs from the stored pair',
			function () {
				slosm_picker_stage(
					array(
						'lat'     => '52.2297',
						'lng'     => '21.0122',
						'address' => 'Nowy Świat 1',
						'city'    => 'Warszawa',
					)
				);

				// What a drag leaves behind: the two inputs, written to
				// COORDINATE_DECIMALS, and an empty lookup field — the picker
				// clears it on every drag, because a pin a person moved is a
				// pin a person placed however it got there first.
				$stored = slosm_picker_save(
					array(
						'lat'     => '52.2301234',
						'lng'     => '21.0130000',
						'lookup'  => '',
						'address' => 'Nowy Świat 1',
						'city'    => 'Warszawa',
					)
				);

				assert_same( '1', $stored['lat_locked'], 'a dragged pin did not lock the location' );
				assert_same( '52.2301234', $stored['lat'] );
				assert_same( '21.013', $stored['lng'] );

				// And the lock is what keeps the geocoder off it, which is the
				// only reason the lock exists.
				assert_false(
					Admin::should_geocode(
						array( 'address' => 'Nowy Świat 1' ),
						array( 'address' => 'Something else entirely' ),
						52.2301234,
						21.013,
						true
					)
				);
			}
		);

		it(
			'does not lock when the pin was put back where it already was',
			function () {
				slosm_picker_stage( array( 'lat' => '52.2297', 'lng' => '21.0122' ) );

				// The picker writes what Admin::coordinate_string() writes, so
				// a drag that ends on the stored point posts the identical
				// string and the save sees no change. This is the case that
				// makes COORDINATE_DECIMALS travelling in the config load
				// bearing rather than tidy.
				$stored = slosm_picker_save( array( 'lat' => '52.2297', 'lng' => '21.0122', 'lookup' => '' ) );

				assert_same( '', $stored['lat_locked'], 'a pin nobody moved locked the location' );
			}
		);

		it(
			'clears the lock when the pair came from the lookup button',
			function () {
				slosm_picker_stage(
					array(
						'lat'        => '52.2297',
						'lng'        => '21.0122',
						'lat_locked' => '1',
						'address'    => 'Nowy Świat 1',
					)
				);

				$stored = slosm_picker_save(
					array(
						'lat'     => '52.4064',
						'lng'     => '16.9252',
						'lookup'  => '52.4064,16.9252',
						'address' => 'Nowy Świat 1',
					)
				);

				// Different coordinates, and still not locked: the pair posted
				// is the pair the lookup wrote, so nobody's hand put it there.
				// This is the only way out of a lock, and it is the recovery
				// path for the half-pair guard as well.
				assert_same( '', $stored['lat_locked'], 'the lookup button did not clear the lock' );
				assert_same( '52.4064', $stored['lat'] );
				assert_same( '16.9252', $stored['lng'] );
			}
		);

		it(
			'locks again when the pin was dragged after the lookup',
			function () {
				slosm_picker_stage( array( 'lat' => '52.2297', 'lng' => '21.0122' ) );

				// The separating input for the rule above. A flag saying "a
				// lookup happened" would call this unlocked; the field carries
				// the pair the lookup produced, so a pair that is not it is a
				// pair somebody moved afterwards.
				$stored = slosm_picker_save(
					array(
						'lat'    => '52.4100000',
						'lng'    => '16.9300000',
						'lookup' => '52.4064,16.9252',
					)
				);

				assert_same( '1', $stored['lat_locked'], 'a drag after a lookup left the location unlocked' );
			}
		);

		it(
			'ignores a lookup field that is not a pair',
			function () {
				slosm_picker_stage( array( 'lat' => '52.2297', 'lng' => '21.0122' ) );

				foreach ( array( 'yes', '1', '52.4064', '52.4064,16.9252,3', ',', '52,4064,16,9252', 'a,b' ) as $forged ) {
					slosm_picker_stage( array( 'lat' => '52.2297', 'lng' => '21.0122' ) );

					$stored = slosm_picker_save(
						array(
							'lat'    => '52.4064',
							'lng'    => '16.9252',
							'lookup' => $forged,
						)
					);

					assert_same( '1', $stored['lat_locked'], 'the lookup field "' . $forged . '" unlocked the location' );
				}
			}
		);

		it(
			'refuses a lookup field whose halves are not coordinates',
			function () {
				// The separating input for lookup_pair()'s own parse check, and
				// it is narrow because it has to be: the only way a half-read
				// pair changes the answer is when the submitted pair is half a
				// pair too, which is what an import that wrote a latitude and no
				// longitude leaves behind. Submitted unchanged, clean() leaves
				// it alone — so a lookup field of "52.2297,x" read as
				// ( 52.2297, null ) would match it and quietly unlock a
				// location somebody had pinned by hand.
				slosm_picker_stage(
					array(
						'lat'        => '52.2297',
						'lat_locked' => '1',
					)
				);

				$stored = slosm_picker_save(
					array(
						'lat'    => '52.2297',
						'lng'    => '',
						'lookup' => '52.2297,x',
					)
				);

				assert_same( '1', $stored['lat_locked'], 'half a lookup pair unlocked a hand-placed pin' );

				// And the control: the same shape with both halves real does
				// clear it, so the refusal above is about the "x".
				slosm_picker_stage(
					array(
						'lat'        => '52.2297',
						'lng'        => '21.0122',
						'lat_locked' => '1',
					)
				);

				$stored = slosm_picker_save(
					array(
						'lat'    => '52.2297',
						'lng'    => '21.0122',
						'lookup' => '52.2297,21.0122',
					)
				);

				assert_same( '', $stored['lat_locked'] );
			}
		);

		it(
			'holds each half of the lookup pair to its own limit',
			function () {
				slosm_picker_stage( array( 'lat' => '52.2297', 'lng' => '21.0122' ) );

				// 120 is a longitude and not a latitude. A lookup pair read
				// with one limit for both halves would refuse this one and lock
				// a location the geocoder had just placed — the same mistake
				// LNG_LIMIT exists to prevent in the fields above.
				$stored = slosm_picker_save(
					array(
						'lat'    => '52.4064',
						'lng'    => '120',
						'lookup' => '52.4064,120',
					)
				);

				assert_same( '', $stored['lat_locked'], 'a longitude past 90 made the lookup unreadable' );
				assert_same( '120', $stored['lng'] );
			}
		);

		it(
			'leaves a location cleared of both coordinates unlocked, as it always did',
			function () {
				slosm_picker_stage(
					array(
						'lat'        => '52.2297',
						'lng'        => '21.0122',
						'lat_locked' => '1',
						'address'    => 'Nowy Świat 1',
					)
				);

				$GLOBALS['slosm_stub']['http_queue'][] = array(
					'response' => array( 'code' => 200 ),
					'body'     => '[{"lat":"52.2297","lon":"21.0122"}]',
				);

				$stored = slosm_picker_save(
					array(
						'lat'     => '',
						'lng'     => '',
						'lookup'  => '',
						'address' => 'Nowy Świat 1',
					)
				);

				// Task 17's rule, unchanged by the lookup field: emptying both
				// is still how an editor asks for the address again without
				// pressing anything.
				assert_same( '', $stored['lat_locked'] );
			}
		);
	}
);

describe(
	'the message the block editor used to eat',
	function () {

		it(
			'leaves the message alone on the render nobody will ever see',
			function () {
				slosm_picker_admin_request( 'post.php', 'post', Post_Type::POST_TYPE );

				$key = Admin::NOTICE_PREFIX . SLOSM_PICKER_POST_ID . '_0';

				set_transient(
					$key,
					array( array( 'text' => 'Latitude “52,2297” was read as 52.2297.', 'warning' => true ) ),
					Admin::NOTICE_TTL
				);

				$html = slosm_picker_render( SLOSM_PICKER_POST_ID, array( Admin::DISCARDED_ARG => '1' ) );

				assert_false(
					false !== strpos( $html, 'was read as' ),
					'the discarded render printed the message into html nobody reads'
				);
				assert_true(
					is_array( get_transient( $key ) ),
					'the discarded render consumed the message, which is the whole bug'
				);

				// The control, and it is the whole of what this claim is worth:
				// the very next render — an ordinary page load, with no marker
				// on it — shows the message and takes it.
				$seen = slosm_picker_render();

				assert_contains( 'was read as', $seen );
				assert_false( is_array( get_transient( $key ) ), 'the message was never consumed' );
			}
		);

		it(
			'lets a later save with nothing to say supersede a note nobody read',
			function () {
				slosm_picker_admin_request( 'post.php', 'post', Post_Type::POST_TYPE );

				$key = Admin::NOTICE_PREFIX . SLOSM_PICKER_POST_ID . '_1';

				slosm_picker_stage( array( 'lat' => '52.2297', 'lng' => '21.0122' ) );

				// A save that says something, and a marked render that leaves
				// it alone — the message is now waiting for a page load.
				slosm_picker_save( array( 'lat' => '52,2297', 'lng' => '21.0122' ) );

				assert_true( is_array( get_transient( $key ) ) );

				slosm_picker_render( SLOSM_PICKER_POST_ID, array( Admin::DISCARDED_ARG => '1' ) );

				assert_true( is_array( get_transient( $key ) ), 'the marked render consumed it after all' );

				// A second save inside the five-minute ttl with nothing to say.
				// The note goes, unseen. That is a decision rather than a leak:
				// a save that has nothing to say has superseded what the one
				// before it said, and the alternative — keeping it — warns about
				// a value the field no longer holds. It is also the limit of
				// what this task's channel promises, which is "the next page
				// load after a save that had something to say".
				slosm_picker_save( array( 'lat' => '52.2297', 'lng' => '21.0122' ) );

				assert_false( is_array( get_transient( $key ) ), 'a save with nothing to say left the old note behind' );

				assert_false(
					false !== strpos( slosm_picker_render(), 'was read as' ),
					'a superseded note was printed anyway'
				);
			}
		);

		it(
			'marks the redirect of a metabox save and leaves a classic one alone',
			function () {
				slosm_picker_post();

				$admin    = new Admin( new Store_Repository() );
				$location = 'https://example.test/wp-admin/post.php?post=' . SLOSM_PICKER_POST_ID . '&action=edit&message=4';

				// The classic editor: post.php?action=editpost, no
				// meta-box-loader anywhere, and the browser follows this
				// redirect and sees the page. Nothing may be added to it.
				$_GET = array();

				try {
					assert_same( $location, $admin->mark_discarded_render( $location, SLOSM_PICKER_POST_ID ) );

					// The block editor: the metabox save is posted to
					// post.php?post=…&action=edit&meta-box-loader=1, so the
					// query string still says so while redirect_post() runs.
					// This is the one moment both facts are in hand — the
					// followed GET will not carry it, because redirect_post()
					// builds its location from get_edit_post_link() afresh.
					$_GET = array( 'meta-box-loader' => '1' );

					$marked = $admin->mark_discarded_render( $location, SLOSM_PICKER_POST_ID );

					assert_contains( Admin::DISCARDED_ARG . '=1', $marked );
					assert_contains( $location, $marked );

					// Never meta-box-loader itself: use_block_editor_for_post()
					// answers that argument with check_admin_referer(
					// 'meta-box-loader', 'meta-box-loader-nonce' ), and the
					// nonce is not in this url — so re-using core's own name
					// would turn the discarded render into a wp_die( -1 ).
					assert_false( false !== strpos( $marked, 'meta-box-loader=' ) );
				} finally {
					$_GET = array();
				}
			}
		);

		it(
			'marks nothing for another post type, and nothing with a fragment',
			function () {
				$GLOBALS['slosm_stub']['posts_by_id'][ SLOSM_PICKER_POST_ID ] = (object) array(
					'ID'        => SLOSM_PICKER_POST_ID,
					'post_type' => 'post',
				);

				$admin    = new Admin( new Store_Repository() );
				$location = 'https://example.test/wp-admin/post.php?post=1&action=edit&message=4';

				$_GET = array( 'meta-box-loader' => '1' );

				try {
					assert_same( $location, $admin->mark_discarded_render( $location, SLOSM_PICKER_POST_ID ) );

					// An ordinary post's redirect is none of this plugin's
					// business, and the control is that the same call for a
					// location does mark it.
					slosm_picker_post();

					assert_contains( Admin::DISCARDED_ARG, $admin->mark_discarded_render( $location, SLOSM_PICKER_POST_ID ) );

					// redirect_post()'s addmeta and deletemeta branches end on
					// '#postcustom' — wp-admin/includes/post.php lines 2216-2223
					// — and add_query_arg() would put the argument after the
					// fragment, where nothing reads it. Such a location is left
					// exactly as it was.
					$fragment = 'https://example.test/wp-admin/post.php?post=1&action=edit&message=2#postcustom';

					assert_same( $fragment, $admin->mark_discarded_render( $fragment, SLOSM_PICKER_POST_ID ) );

					// And a location that is not a string at all, which is what
					// a filter above this one can hand over.
					assert_same( null, $admin->mark_discarded_render( null, SLOSM_PICKER_POST_ID ) );
				} finally {
					$_GET = array();
				}
			}
		);

		it(
			'is hooked from boot(), on redirect_post_location with both arguments',
			function () {
				slosm_picker_boot();

				$hooked = array();

				foreach ( $GLOBALS['slosm_stub']['filters'] as $hook => $registrations ) {
					foreach ( $registrations as $registered ) {
						if ( is_array( $registered['callback'] )
							&& $registered['callback'][0] instanceof Admin
							&& 'mark_discarded_render' === $registered['callback'][1] ) {
							$hooked[] = array( $hook, $registered['accepted_args'] );
						}
					}
				}

				// Two arguments, because the post id is what says whether this
				// redirect is a location's at all.
				assert_same( array( array( 'redirect_post_location', 2 ) ), $hooked );
			}
		);
	}
);

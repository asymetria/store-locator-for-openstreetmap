<?php
/**
 * Proves the locator ships nothing to a page that has no locator on it.
 *
 * That is the whole of this task, and it is the thing most map plugins get
 * wrong: a site with one locator on one contact page pays for Leaflet, a
 * stylesheet and a script on its home page, its blog, its checkout and its
 * 404. The mechanism is register-on-hook, enqueue-on-render, and both halves
 * have to be asserted or the case is worthless.
 *
 * Why every negative case here carries a control
 * ----------------------------------------------
 * "Nothing was enqueued" is the expected result of a correct plugin and of a
 * completely broken one alike. An Assets class whose methods are empty passes
 * every absence assertion in this file. So each of them is paired with the
 * positive half in the same case: a page with no locator queues nothing *and*
 * a page with one queues exactly the locator, in one case, against one boot.
 * Split across two cases, deleting the enqueue would leave the first green.
 *
 * What a stub cannot prove, and what is asserted instead
 * -----------------------------------------------------
 * Nothing in this suite prints a script tag, so no case here can watch Leaflet
 * arrive before the locator script in the html. What the plugin controls is the
 * *declaration*, and that is what is asserted: the dependency, the version, the
 * group, the strategy. The behaviour those declarations buy was read out of
 * WordPress 6.9.1 rather than assumed, and the places it was read are named in
 * includes/class-assets.php so that neither this file nor that one has to be
 * believed on its own.
 *
 * The one core fact worth repeating here, because two cases below rest on it:
 * WP_Dependencies::all_deps() walks a handle's dependencies and appends them to
 * to_do *before* the handle itself, and abandons the handle entirely when a
 * dependency is not registered (wp-includes/class-wp-dependencies.php, lines
 * 216-251 of 6.9.1). So declaring slosm-leaflet as a dependency of
 * slosm-locator is not decoration — it is both the ordering and the reason the
 * locator script is never printed without the library it needs.
 *
 * Both theme orders, because there are two
 * ----------------------------------------
 * A classic theme renders the_content from inside the body, after wp_head has
 * fired wp_enqueue_scripts. A block theme renders the whole template *above*
 * the doctype, so the same shortcode runs before wp_head exists. Every case
 * about enqueuing is written against one of the two on purpose, and the pair of
 * block-theme cases exists because this plugin shipped an arrangement that
 * worked perfectly on the first and silently dropped the interface strings on
 * the second.
 *
 * That bug survived a full suite because the stub was wrong, not because no
 * case looked. tests/bootstrap.php used to queue a handle that was not
 * registered, which WordPress does not do — it holds it aside — so a guard
 * written against wp_script_is() short-circuited here and did not there. The
 * stub now models $queued_before_register, its replay, and recurse_deps(). It
 * is worth saying plainly: a stub that is wrong about core does not make a test
 * weak, it makes it say the opposite of the truth.
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
// Plugin::boot() constructs an Admin for the location metabox, and this suite
// runs without the autoloader.
require_once dirname( __DIR__ ) . '/admin/class-admin.php';
require_once dirname( __DIR__ ) . '/admin/class-locations-list.php';
require_once dirname( __DIR__ ) . '/admin/class-bulk-geocode.php';

use Asymetria\StoreLocator\Assets;
use Asymetria\StoreLocator\Plugin;
use Asymetria\StoreLocator\Shortcode;

if ( ! function_exists( 'slosm_assets_boot' ) ) {
	/**
	 * A freshly booted plugin, standing for one request.
	 *
	 * A fresh instance rather than Plugin::instance(), because the singleton
	 * boots at most once per process and every case after the first would then
	 * hook nothing at all — which is precisely the state every absence
	 * assertion in this file would pass in.
	 *
	 * @return Plugin
	 */
	function slosm_assets_boot(): Plugin {
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

if ( ! function_exists( 'slosm_assets_front_page' ) ) {
	/**
	 * One front-end request, up to the point the content is about to render.
	 *
	 * init fires, then wp_enqueue_scripts, in the order WordPress fires them:
	 * wp_enqueue_scripts is hung on wp_head at priority 1
	 * (wp-includes/default-filters.php line 345 of 6.9.1), so by the time a
	 * shortcode inside the_content() runs, it has already fired and the head
	 * scripts have already been printed.
	 *
	 * Stub state is written, so this may only be called from inside it().
	 *
	 * @return callable The registered [store_locator] callback.
	 */
	function slosm_assets_front_page(): callable {
		slosm_assets_boot();

		do_action( 'init' );
		do_action( 'wp_enqueue_scripts' );

		return $GLOBALS['slosm_stub']['shortcodes'][ Shortcode::TAG ][0]['callback'];
	}
}

if ( ! function_exists( 'slosm_assets_block_theme_page' ) ) {
	/**
	 * One front-end request on a block theme, which renders in the other order.
	 *
	 * wp-includes/template-canvas.php — the file every block theme renders
	 * through — calls get_the_block_template_html() *above* the doctype, with
	 * core's own comment saying why: "This needs to run before <head> so that
	 * blocks can add scripts and styles in wp_head()". So the whole template,
	 * core/post-content and the_content and every shortcode in it included, has
	 * already run by the time wp_head() fires wp_enqueue_scripts.
	 *
	 * The classic order is the one slosm_assets_front_page() models. Both are
	 * ordinary; neither is a misconfiguration; and a plugin that only works in
	 * one of them works on about half the themes being written today.
	 *
	 * Stub state is written, so this may only be called from inside it().
	 *
	 * @param int $locators How many locators the template contains.
	 * @return string The rendered template html.
	 */
	function slosm_assets_block_theme_page( int $locators = 1 ): string {
		slosm_assets_boot();

		do_action( 'init' );

		$render = $GLOBALS['slosm_stub']['shortcodes'][ Shortcode::TAG ][0]['callback'];
		$html   = '';

		for ( $i = 0; $i < $locators; $i++ ) {
			$html .= $render( array() );
		}

		// Only now does the head run.
		do_action( 'wp_enqueue_scripts' );

		return $html;
	}
}

if ( ! function_exists( 'slosm_assets_script' ) ) {
	/**
	 * One registered script, or an empty shape when nothing registered it.
	 *
	 * The empty shape keeps a failing case reporting the assertion that failed
	 * rather than an "Undefined array key" from the assertion's own setup.
	 *
	 * @param string $handle Script handle.
	 * @return array
	 */
	function slosm_assets_script( string $handle ): array {
		return $GLOBALS['slosm_stub']['scripts'][ $handle ] ?? array(
			'src'   => null,
			'deps'  => array(),
			'ver'   => null,
			'extra' => array(),
			'l10n'  => array(),
		);
	}
}

if ( ! function_exists( 'slosm_assets_style' ) ) {
	/**
	 * One registered style, or an empty shape when nothing registered it.
	 *
	 * @param string $handle Style handle.
	 * @return array
	 */
	function slosm_assets_style( string $handle ): array {
		return $GLOBALS['slosm_stub']['styles'][ $handle ] ?? array(
			'src'   => null,
			'deps'  => array(),
			'ver'   => null,
			'media' => null,
		);
	}
}

if ( ! function_exists( 'slosm_assets_config_from_html' ) ) {
	/**
	 * The config a browser would read off one rendered locator.
	 *
	 * A second copy of test-shortcode.php's helper rather than a shared one,
	 * because run.php loads the test files in glob order and this file is
	 * loaded first: a function defined over there does not exist here. Cut at
	 * the first double quote the way a browser's tokeniser ends an attribute,
	 * then entity-decoded and json-decoded, in the order a browser does it.
	 *
	 * @param string $html Rendered markup.
	 * @return mixed Decoded config, or null.
	 */
	function slosm_assets_config_from_html( string $html ) {
		$needle = ' data-slosm="';
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
	'asset registration',
	function () {

		it(
			'registers on init and enqueues nothing there',
			function () {
				slosm_assets_boot();

				// Booting is hooking. A plugin that registered its handles here
				// would be calling wp_register_script() before init, which
				// WordPress answers with _doing_it_wrong().
				assert_same( array(), $GLOBALS['slosm_stub']['scripts'], 'boot() registered scripts directly' );
				assert_same( array(), $GLOBALS['slosm_stub']['styles'], 'boot() registered styles directly' );

				$hooked = array();

				foreach ( $GLOBALS['slosm_stub']['actions']['init'] ?? array() as $registered ) {
					if ( $registered['callback'][0] instanceof Assets ) {
						$hooked[] = $registered;
					}
				}

				assert_same( 1, count( $hooked ), 'the asset registration is not hooked onto init exactly once' );
				assert_same( 'register', $hooked[0]['callback'][1] );

				// init, and not wp_enqueue_scripts. tests/test-cache-invalidation.php
				// pins the absence of that hook in the boot inventory; the
				// reason is the block-theme case further down this file.
				assert_false( array_key_exists( 'wp_enqueue_scripts', $GLOBALS['slosm_stub']['actions'] ) );

				do_action( 'init' );

				// The positive half, and the reason the two assertions below are
				// worth anything: a class whose register() is empty fails here
				// and would sail through "nothing was enqueued".
				assert_true( wp_script_is( 'slosm-locator', 'registered' ), 'the locator script was never registered' );
				assert_true( wp_style_is( 'slosm-locator-css', 'registered' ), 'the locator stylesheet was never registered' );

				assert_same(
					array(),
					$GLOBALS['slosm_stub']['script_queue'],
					'a script was enqueued during registration, so every page on the site carries it'
				);
				assert_same(
					array(),
					$GLOBALS['slosm_stub']['style_queue'],
					'a stylesheet was enqueued during registration, so every page on the site carries it'
				);
			}
		);

		it(
			'registers eight handles and no others, all under the slosm- prefix',
			function () {
				slosm_assets_front_page();

				// Written out rather than read from the class, because a handle
				// is public API: another plugin swapping the bundled Leaflet
				// deregisters this exact string, and a test that read the
				// constant would agree with any rename.
				//
				// Three of the eight are the cluster library, and registering
				// them on every front-end request is not a contradiction of the
				// conditional loading. Registration writes an array entry and
				// emits nothing; the case further down asserts that a site below
				// the clustering threshold never *enqueues* any of them, which is
				// the half that costs a visitor bytes.
				assert_same(
					array( 'slosm-leaflet', 'slosm-locator', 'slosm-markercluster' ),
					array_keys( $GLOBALS['slosm_stub']['scripts'] )
				);
				assert_same(
					array( 'slosm-leaflet-css', 'slosm-locator-css', 'slosm-locator-skin-css', 'slosm-markercluster-css', 'slosm-markercluster-default-css' ),
					array_keys( $GLOBALS['slosm_stub']['styles'] )
				);

				assert_same( 'slosm-leaflet', Assets::SCRIPT_LEAFLET );
				assert_same( 'slosm-leaflet-css', Assets::STYLE_LEAFLET );
				assert_same( 'slosm-locator', Assets::SCRIPT_LOCATOR );
				assert_same( 'slosm-locator-css', Assets::STYLE_LOCATOR );
				assert_same( 'slosm-locator-skin-css', Assets::STYLE_LOCATOR_SKIN );
				assert_same( 'slosm-markercluster', Assets::SCRIPT_CLUSTER );
				assert_same( 'slosm-markercluster-css', Assets::STYLE_CLUSTER );
				assert_same( 'slosm-markercluster-default-css', Assets::STYLE_CLUSTER_DEFAULT );
			}
		);

		it(
			'versions every handle from SLOSM_VERSION, never from false',
			function () {
				slosm_assets_front_page();

				// false is the default, and it is the bug: WP_Scripts::do_item()
				// falls back to $this->default_version, which is the WordPress
				// version (class-wp-scripts.php line 302 of 6.9.1). Every handle
				// would then be cache-busted by WordPress upgrades and by
				// nothing this plugin ever does, so a fixed script.js stays in
				// every visitor's cache across a plugin update.
				foreach ( array( 'slosm-leaflet', 'slosm-locator', 'slosm-markercluster' ) as $handle ) {
					assert_same( SLOSM_VERSION, slosm_assets_script( $handle )['ver'], $handle . ' is not versioned from SLOSM_VERSION' );
				}

				foreach ( array( 'slosm-leaflet-css', 'slosm-locator-css', 'slosm-markercluster-css', 'slosm-markercluster-default-css' ) as $handle ) {
					assert_same( SLOSM_VERSION, slosm_assets_style( $handle )['ver'], $handle . ' is not versioned from SLOSM_VERSION' );
				}

				// Without this the loop above passes on a site where the
				// constant is itself false or empty, which is the same broken
				// cache-busting with a different cause.
				assert_true( is_string( SLOSM_VERSION ) && '' !== SLOSM_VERSION );
			}
		);

		it(
			'points every handle at a file inside this plugin',
			function () {
				slosm_assets_front_page();

				$expected = array(
					'slosm-leaflet'      => 'assets/leaflet/leaflet.js',
					'slosm-locator'      => 'assets/js/locator.js',
					'slosm-markercluster' => 'assets/markercluster/leaflet.markercluster.js',
				);

				foreach ( $expected as $handle => $path ) {
					assert_same( SLOSM_URL . $path, slosm_assets_script( $handle )['src'] );
				}

				assert_same( SLOSM_URL . 'assets/leaflet/leaflet.css', slosm_assets_style( 'slosm-leaflet-css' )['src'] );
				assert_same( SLOSM_URL . 'assets/css/locator.css', slosm_assets_style( 'slosm-locator-css' )['src'] );
				assert_same( SLOSM_URL . 'assets/markercluster/MarkerCluster.css', slosm_assets_style( 'slosm-markercluster-css' )['src'] );
				assert_same( SLOSM_URL . 'assets/markercluster/MarkerCluster.Default.css', slosm_assets_style( 'slosm-markercluster-default-css' )['src'] );

				// The files are really there, which is the half a url cannot
				// say. A handle pointing at a path this plugin does not ship
				// answers 404 and reads as "L.markerClusterGroup is not a
				// function" in a console, which is the failure the vendoring
				// exists to prevent.
				foreach ( $expected as $path ) {
					assert_true( file_exists( dirname( __DIR__ ) . '/' . $path ), $path . ' is not in this plugin' );
				}

				// No src is not an alias here. WP_Scripts::add_data() refuses a
				// delayed strategy on a handle with no src, and all_deps() skips
				// any handle whose own dependencies are missing, so an empty src
				// would take the locator script off the page silently.
				foreach ( array( 'slosm-leaflet', 'slosm-locator', 'slosm-markercluster' ) as $handle ) {
					assert_true( is_string( slosm_assets_script( $handle )['src'] ) && '' !== slosm_assets_script( $handle )['src'] );
				}
			}
		);

		it(
			'declares the locator behind Leaflet, in script and in stylesheet alike',
			function () {
				slosm_assets_front_page();

				assert_same( array( 'slosm-leaflet' ), slosm_assets_script( 'slosm-locator' )['deps'] );
				assert_same( array( 'slosm-leaflet-css' ), slosm_assets_style( 'slosm-locator-css' )['deps'] );

				// The cluster library behind Leaflet too, and its default skin
				// behind its own base stylesheet. The skin is the only cluster
				// handle Assets ever enqueues; the base arrives through this
				// declaration, the same way Leaflet arrives behind the locator.
				assert_same( array( 'slosm-leaflet' ), slosm_assets_script( 'slosm-markercluster' )['deps'] );
				assert_same( array( 'slosm-leaflet-css' ), slosm_assets_style( 'slosm-markercluster-css' )['deps'] );
				assert_same( array( 'slosm-markercluster-css' ), slosm_assets_style( 'slosm-markercluster-default-css' )['deps'] );

				// Leaflet depends on nothing, which is what makes the chain
				// terminate rather than a claim about Leaflet.
				assert_same( array(), slosm_assets_script( 'slosm-leaflet' )['deps'] );
				assert_same( array(), slosm_assets_style( 'slosm-leaflet-css' )['deps'] );

				// And the dependency has to be resolvable. all_deps() abandons a
				// handle whose dependencies are not registered — silently before
				// 6.9.1, with a _doing_it_wrong() since — so a dependency naming
				// a handle nobody registered is not a load order, it is a script
				// that never loads at all.
				assert_true( wp_script_is( 'slosm-leaflet', 'registered' ) );
				assert_true( wp_style_is( 'slosm-leaflet-css', 'registered' ) );
			}
		);

		it(
			'asks for the footer with a boolean, so a 6.0 site reads the same thing',
			function () {
				slosm_assets_front_page();

				// The fifth argument of wp_register_script() was a boolean
				// $in_footer until 6.3.0 overloaded it into an $args array
				// (functions.wp-scripts.php line 160 of 6.9.1 says so in as many
				// words). This plugin's floor is 6.0. A boolean is the one shape
				// both ends of that range were written to take, and 6.9.1
				// normalises it to array( 'in_footer' => true ) on arrival, so
				// nothing is given up by passing it.
				assert_same( array( true ), $GLOBALS['slosm_stub']['script_args']['slosm-locator'] ?? array() );
				assert_same( array( true ), $GLOBALS['slosm_stub']['script_args']['slosm-leaflet'] ?? array() );
				assert_same( array( true ), $GLOBALS['slosm_stub']['script_args']['slosm-markercluster'] ?? array() );

				assert_same( 1, slosm_assets_script( 'slosm-locator' )['extra']['group'] ?? 0 );
				assert_same( 1, slosm_assets_script( 'slosm-leaflet' )['extra']['group'] ?? 0 );
				assert_same( 1, slosm_assets_script( 'slosm-markercluster' )['extra']['group'] ?? 0 );
			}
		);

		it(
			'defers the locator script and only the locator script',
			function () {
				slosm_assets_front_page();

				assert_same( 'defer', slosm_assets_script( 'slosm-locator' )['extra']['strategy'] ?? '' );

				// Leaflet stays blocking on purpose, and "on purpose" is the
				// whole of it: core would in fact let it be deferred.
				// WP_Scripts::filter_eligible_strategies() narrows a handle's
				// strategies by its *dependents*, not its dependencies, and the
				// only dependent here is slosm-locator, whose own eligible set
				// is exactly array( 'defer' ). An earlier version of this
				// comment claimed core would refuse, which was wrong.
				//
				// It is left blocking so that window.L exists synchronously for
				// anything else on the page — a theme's inline script, another
				// plugin — rather than appearing partway through the deferred
				// queue. Either would work; this is the smaller claim to make
				// about a library this plugin only vendors.
				assert_false( array_key_exists( 'strategy', slosm_assets_script( 'slosm-leaflet' )['extra'] ) );

				// The cluster library stays blocking, and here that is
				// load-bearing rather than a preference. It is enqueued from
				// render() *after* the locator script, because whether a locator
				// clusters is not known until its config has been built — so the
				// html carries the deferred locator tag first and the blocking
				// cluster tag second. A deferred script runs only once the parser
				// has finished, by which time every blocking script in the
				// document has executed, so window.L.markerClusterGroup is there
				// when locator.js runs. Deferring the library too would put the
				// two in document order and break exactly that.
				//
				// It is not relied on alone: init() checks for
				// L.markerClusterGroup and falls back to plain pins, and
				// tests/js/nearby.test.js has the case.
				//
				// The registration check first, because slosm_assets_script()
				// answers an unregistered handle with an empty shape — so
				// "carries no strategy" is true of a library nobody registered
				// at all, which is the state this assertion is supposed to be
				// able to tell apart from the one it is asserting.
				assert_true( wp_script_is( 'slosm-markercluster', 'registered' ), 'the cluster library was never registered' );
				assert_false( array_key_exists( 'strategy', slosm_assets_script( 'slosm-markercluster' )['extra'] ) );
			}
		);

		it(
			'leaves a locator handle somebody else registered alone',
			function () {
				slosm_assets_boot();

				// A plugin, a theme or a site snippet that got to this handle
				// first, on an earlier init priority. WP_Dependencies::add()
				// keeps the first registration and answers false
				// (class-wp-dependencies.php lines 283-286), so the script on
				// the page is theirs.
				wp_register_script( 'slosm-locator', 'https://example.test/their-locator.js', array(), '2.0', false );

				do_action( 'init' );

				assert_same( 'https://example.test/their-locator.js', slosm_assets_script( 'slosm-locator' )['src'] );
				assert_same( '2.0', slosm_assets_script( 'slosm-locator' )['ver'] );

				// And this plugin does not stamp a loading strategy onto it.
				// Reading wp_register_script()'s return is the only way to know:
				// a script that is not ours may carry inline code after it that
				// must not be delayed, and deferring it would be this plugin
				// silently changing when somebody else's JavaScript runs.
				assert_false(
					array_key_exists( 'strategy', slosm_assets_script( 'slosm-locator' )['extra'] ),
					'a defer was applied to a script another plugin had registered'
				);

				// The control: the handles this plugin did register still got
				// everything, so the guard is about ownership rather than about
				// register() having given up.
				assert_same( SLOSM_VERSION, slosm_assets_script( 'slosm-leaflet' )['ver'] );
				assert_same( 1, slosm_assets_script( 'slosm-leaflet' )['extra']['group'] ?? 0 );
			}
		);
	}
);

describe(
	'conditional loading',
	function () {

		it(
			'loads nothing on a page with no locator, and the locator on a page with one',
			function () {
				$render = slosm_assets_front_page();

				// A page of ordinary content. Nothing called the shortcode,
				// because there is no shortcode in it.
				assert_same(
					array(),
					$GLOBALS['slosm_stub']['script_queue'],
					'a page with no locator on it shipped a script'
				);
				assert_same(
					array(),
					$GLOBALS['slosm_stub']['style_queue'],
					'a page with no locator on it shipped a stylesheet'
				);
				assert_same( array(), $GLOBALS['slosm_stub']['localize_calls'] );

				// The other page on the same site. Without this half, the three
				// assertions above are satisfied by a plugin that enqueues
				// nothing anywhere, which is not conditional loading — it is a
				// map that never draws.
				$render( array() );

				assert_same( array( 'slosm-locator' ), $GLOBALS['slosm_stub']['script_queue'] );
				assert_same( array( 'slosm-locator-css', 'slosm-locator-skin-css' ), $GLOBALS['slosm_stub']['style_queue'] );
				assert_same( 1, count( $GLOBALS['slosm_stub']['localize_calls'] ) );
			}
		);

		it(
			'ships the cluster library to a big site and to no small one',
			function () {
				// The whole of Task 15's half of the conditional loading, in one
				// case against one boot, for the reason this file's header
				// gives: "the cluster library was not enqueued" is the expected
				// result of a correct plugin and of one whose enqueue_cluster()
				// is an empty method, and splitting the two halves would leave
				// the first green while the feature never shipped at all.
				$render = slosm_assets_front_page();

				// A site with twenty branches, which is the example the vendored
				// README names. 34 KB of JavaScript and two stylesheets for a map
				// that would show every one of those twenty pins anyway.
				$GLOBALS['slosm_stub']['post_counts'][ \Asymetria\StoreLocator\Post_Type::POST_TYPE ] = array( 'publish' => 20 );

				$render( array() );

				assert_same( array( 'slosm-locator' ), $GLOBALS['slosm_stub']['script_queue'], 'a twenty-branch site downloaded the cluster library' );
				assert_same( array( 'slosm-locator-css', 'slosm-locator-skin-css' ), $GLOBALS['slosm_stub']['style_queue'] );

				// The same site the day it crosses the line. Nothing else about
				// the request changes.
				$GLOBALS['slosm_stub']['post_counts'][ \Asymetria\StoreLocator\Post_Type::POST_TYPE ] = array( 'publish' => 400 );

				$render( array() );

				assert_same(
					array( 'slosm-locator', 'slosm-markercluster' ),
					$GLOBALS['slosm_stub']['script_queue'],
					'a four-hundred-branch site did not get the cluster library'
				);

				// One stylesheet enqueued, two printed: the base arrives as the
				// skin's declared dependency, the way Leaflet arrives behind the
				// locator script.
				assert_same(
					array( 'slosm-locator-css', 'slosm-locator-skin-css', 'slosm-markercluster-default-css' ),
					$GLOBALS['slosm_stub']['style_queue']
				);
				assert_true( wp_style_is( 'slosm-markercluster-css', 'enqueued' ) );
			}
		);

		it(
			'ships it to a small site that asked for it, and not to a big one that refused',
			function () {
				// The other half of the trigger. The count decides by itself,
				// because a site owner cannot be asked a question whose answer
				// depends on how their pins happen to fall on a map — the same
				// argument preload_threshold() makes. The attribute exists for
				// the two cases a count cannot see: twelve shops on one street,
				// which overlap at every zoom, and four hundred spread over a
				// continent, which do not.
				$render = slosm_assets_front_page();

				$GLOBALS['slosm_stub']['post_counts'][ \Asymetria\StoreLocator\Post_Type::POST_TYPE ] = array( 'publish' => 12 );

				$render( array( 'cluster' => 'yes' ) );

				assert_same( array( 'slosm-locator', 'slosm-markercluster' ), $GLOBALS['slosm_stub']['script_queue'] );

				$GLOBALS['slosm_stub']['post_counts'][ \Asymetria\StoreLocator\Post_Type::POST_TYPE ] = array( 'publish' => 900 );

				$html = $render( array( 'cluster' => 'no' ) );

				// Still just the two, because the first locator on the page
				// already queued them and this one asked for nothing more.
				assert_same( array( 'slosm-locator', 'slosm-markercluster' ), $GLOBALS['slosm_stub']['script_queue'] );

				// What that second locator tells the browser is the assertion
				// that can see the difference: its own config says no.
				$config = slosm_assets_config_from_html( $html );

				assert_false( $config['cluster'] );
			}
		);

		it(
			'enqueues once and localizes once for two locators on one page',
			function () {
				$render = slosm_assets_front_page();

				$first  = $render( array( 'zoom' => '9' ) );
				$second = $render( array( 'zoom' => '14' ) );

				// Both locators really rendered; otherwise "once" is trivially
				// true because the second call did nothing at all.
				assert_contains( '<div class="slosm" data-slosm="', $first );
				assert_contains( '<div class="slosm" data-slosm="', $second );

				assert_same( array( 'slosm-locator' ), $GLOBALS['slosm_stub']['script_queue'] );
				assert_same( array( 'slosm-locator-css', 'slosm-locator-skin-css' ), $GLOBALS['slosm_stub']['style_queue'] );

				// This is the one WordPress does not deduplicate for you.
				// wp_enqueue_script() on a handle already in the queue is a
				// no-op, but WP_Scripts::localize() *concatenates* onto whatever
				// the handle already carried, so a second call is a second
				// `var slosmL10n = {...}` statement in the page.
				assert_same(
					1,
					count( $GLOBALS['slosm_stub']['localize_calls'] ),
					'the second locator localized the shared strings again'
				);
				assert_same( 1, count( slosm_assets_script( 'slosm-locator' )['l10n'] ) );
			}
		);

		it(
			'puts the shared strings in one payload keyed to the locator handle',
			function () {
				$render = slosm_assets_front_page();

				$render( array() );

				$call = $GLOBALS['slosm_stub']['localize_calls'][0] ?? array();

				assert_same( 'slosm-locator', $call['handle'] ?? '' );
				assert_same( 'slosmL10n', $call['object'] ?? '' );
				assert_same( 'slosmL10n', Assets::L10N_OBJECT );

				$strings = $call['l10n'] ?? array();

				assert_same( 'No results', $strings['noResults'] ?? '' );
				assert_same( 'Searching…', $strings['searching'] ?? '' );
				assert_same( 'The locations could not be loaded.', $strings['loadFailed'] ?? '' );
				assert_same( 'This map could not start: its settings are missing or unreadable.', $strings['configError'] ?? '' );
				assert_same( 'No place matched that search.', $strings['searchNoMatch'] ?? '' );
				assert_same( 'The address lookup is busy right now. Try again in a moment.', $strings['searchBusy'] ?? '' );
				assert_same( 'That address could not be looked up right now.', $strings['searchFailed'] ?? '' );

				// The two distance formats carry a %s and nothing else that
				// looks like a placeholder. A translation that dropped the %s
				// would print a unit with no number in front of it, on every
				// row of every result list.
				assert_same( '%s km', $strings['distanceKm'] ?? '' );
				assert_same( '%s mi', $strings['distanceMi'] ?? '' );

				// Task 15's four, and the shape of them is the decision rather
				// than the wording. Declining a location prompt is what most
				// visitors do, so it is not an error and does not read like one;
				// a position the browser could not work out is a failure and
				// reads like one; a browser that cannot do it at all is neither.
				// One sentence for all three would leave somebody re-pressing a
				// button that was never going to work, and would paint a red
				// error over the ordinary answer.
				assert_same( 'Finding your location…', $strings['locating'] ?? '' );
				assert_same( 'No location shared. Search for an address instead.', $strings['locationDenied'] ?? '' );
				assert_same( 'Your location could not be worked out. Search for an address instead.', $strings['locationFailed'] ?? '' );
				assert_same( 'This browser cannot share a location. Search for an address instead.', $strings['locationUnsupported'] ?? '' );

				// Three different sentences, counted rather than merely listed:
				// a table where two of them had drifted into being the same
				// string would satisfy every assertion above.
				$located = array( $strings['locating'], $strings['locationDenied'], $strings['locationFailed'], $strings['locationUnsupported'] );

				assert_same( 4, count( array_unique( $located ) ) );

				// Task 16's one. The popup's directions anchor is built in the
				// browser, so its label cannot come from the markup the way the
				// row's does; the two are the same __() argument on purpose, so
				// a translator sees one entry and cannot translate them apart.
				assert_same( 'Directions', $strings['directions'] ?? '' );

				// The one said after every draw a person asked for, and the
				// only sentence a screen reader hears about a redraw at all:
				// since Task 24c the live region is the status line and not the
				// list, so a list that replaces itself is silent.
				//
				// A label and a value rather than a sentence, and that is a
				// decision rather than clumsiness. "7 locations found" needs a
				// plural form; gettext plurals do not survive
				// wp_localize_script() in any shape a browser can apply, the
				// way out is wp.i18n with a build step this plugin does not
				// have, and two forms would be wrong in Polish, which has
				// three. Nothing agrees with the number in this shape, so it is
				// correct for every count in every language.
				assert_same( 'Locations found: %s', $strings['resultsFound'] ?? '' );
				assert_false( array_key_exists( 'resultsUpdated', $strings ), 'the sentence the count replaced is still being shipped' );

				// The front end keeps an English fallback for each of these, so
				// a key added here and forgotten there ships a translated site
				// an untranslated message. tests/js/harness.test.js asserts the
				// two lists against each other; this is the list itself.
				assert_same( 16, count( $strings ) );

				// Attached to the script that reads them, not to the library.
				assert_same( array(), slosm_assets_script( 'slosm-leaflet' )['l10n'] );
			}
		);

		it(
			'keeps the shared strings out of the per-instance attribute',
			function () {
				$render = slosm_assets_front_page();

				$html = $render( array() );

				// The decision Task 11 recorded and this task carries out: a
				// string that is identical for every locator on the site is paid
				// for once per handle, not once per instance. Two locators on a
				// page would otherwise carry two copies of every message in the
				// interface, inside an attribute no cache can share.
				foreach ( array( 'No results', 'Searching…', 'Finding your location…' ) as $string ) {
					assert_same(
						false,
						strpos( $html, $string ),
						'"' . $string . '" is in the markup as well as in the localized payload'
					);
				}

				// The control. Without it this case passes against a plugin that
				// has no interface strings anywhere, which is not the decision
				// being pinned.
				$strings = $GLOBALS['slosm_stub']['localize_calls'][0]['l10n'] ?? array();

				assert_same( 'No results', $strings['noResults'] ?? '' );
			}
		);

		it(
			'leaves Leaflet to the dependency rather than queueing it as well',
			function () {
				$render = slosm_assets_front_page();

				$render( array() );

				// Not an omission. all_deps() walks the locator's dependencies
				// and appends them to to_do before the locator itself, so
				// Leaflet is printed first without being queued — and a site
				// that dequeues slosm-locator gets rid of the library with it,
				// which is not true of a library enqueued in its own right.
				//
				// Asserted on the queue itself and not through wp_script_is(),
				// which cannot answer this question: query( $handle, 'enqueued' )
				// falls through to recurse_deps() (class-wp-dependencies.php
				// lines 483-488), so it says true for a dependency of an
				// enqueued handle as readily as for the handle itself. An
				// earlier version of this case asserted the opposite and passed
				// only because the stub was wrong about it.
				assert_same( array( 'slosm-locator' ), $GLOBALS['slosm_stub']['script_queue'] );
				assert_same( array( 'slosm-locator-css', 'slosm-locator-skin-css' ), $GLOBALS['slosm_stub']['style_queue'] );

				assert_true( wp_script_is( 'slosm-leaflet', 'registered' ) );
				assert_true( wp_style_is( 'slosm-leaflet-css', 'registered' ) );

				// And core does consider Leaflet enqueued, precisely because the
				// locator depends on it. That is the mechanism this case is
				// about, stated from the other side.
				assert_true( wp_script_is( 'slosm-leaflet', 'enqueued' ) );
				assert_true( wp_style_is( 'slosm-leaflet-css', 'enqueued' ) );
			}
		);

		it(
			'does not ask WordPress for script translations there are no files for',
			function () {
				$render = slosm_assets_front_page();

				$render( array() );

				// wp_set_script_translations() is not free and not neutral:
				// WP_Scripts::set_translations() appends 'wp-i18n' to the
				// handle's dependencies (class-wp-scripts.php line 698 of
				// 6.9.1), so every page carrying a locator would load wp-i18n
				// and its own dependencies to read a languages/*.json file this
				// plugin does not ship. The strings the interface needs come
				// through wp_localize_script(), already translated on the
				// server by __(). When Task 14 or later ships JavaScript that
				// calls __() itself, this decision is the one to revisit.
				assert_same( array(), $GLOBALS['slosm_stub']['translation_calls'] );

				// The control: the strings did reach the browser by the other
				// route, so this is a choice between two mechanisms rather than
				// a plugin that never localized anything.
				assert_same( 1, count( $GLOBALS['slosm_stub']['localize_calls'] ) );
			}
		);

		it(
			'delivers the strings on a block theme, which renders its content before the head',
			function () {
				slosm_assets_block_theme_page();

				// The half that was never broken, and it is here so that this
				// case cannot be read as "block themes do not work". The script
				// and the stylesheet do arrive: a handle enqueued before it was
				// registered is held in $queued_before_register and released
				// into the queue by WP_Dependencies::add() when registration
				// finally happens (class-wp-dependencies.php lines 287-295), so
				// both are printed in the footer, versioned and deferred.
				assert_same( array( 'slosm-locator' ), $GLOBALS['slosm_stub']['script_queue'] );
				assert_same( array( 'slosm-locator-css', 'slosm-locator-skin-css' ), $GLOBALS['slosm_stub']['style_queue'] );

				// The half that was. wp_localize_script() is
				// WP_Scripts::add_data() underneath, which returns false on its
				// first line when the handle is not registered yet
				// (class-wp-scripts.php lines 843-845) — so the payload is
				// dropped on the floor, silently, and the replay above brings
				// back the script but not the strings. A map that draws with
				// every message in its interface missing.
				assert_same(
					1,
					count( slosm_assets_script( 'slosm-locator' )['l10n'] ),
					'the shared strings never reached the script on a block theme'
				);

				$attached = slosm_assets_script( 'slosm-locator' )['l10n'][0] ?? array();

				assert_same( 'slosmL10n', $attached['object'] ?? '' );
				assert_same( 'No results', $attached['data']['noResults'] ?? '' );
			}
		);

		it(
			'still localizes once when a block theme renders two locators',
			function () {
				slosm_assets_block_theme_page( 2 );

				// The guard has to survive the reordering too. On the block path
				// the first render cannot attach anything, so a guard that asked
				// "is this handle enqueued" would be satisfied by the first
				// attempt and never let the second one succeed — and a guard
				// that asked nothing would attach twice once registration caught
				// up. Exactly one attachment is the only correct answer.
				assert_same(
					1,
					count( slosm_assets_script( 'slosm-locator' )['l10n'] ),
					'two locators on a block theme did not produce exactly one payload'
				);

				assert_same( array( 'slosm-locator' ), $GLOBALS['slosm_stub']['script_queue'] );
			}
		);

		it(
			'says so, twice over, when it is asked to render after the footer',
			function () {
				$render = slosm_assets_front_page();

				// The footer pass happens. wp_print_footer_scripts() is nothing
				// but do_action( 'wp_print_footer_scripts' ) — script-loader.php
				// lines 2288-2295 — so this is exactly the state a locator
				// rendered from a shutdown hook, or from a wp_footer callback at
				// a priority above 20, would find.
				do_action( 'wp_print_footer_scripts' );

				$html = $render( array() );

				// Without one of these two, the symptom is a blank rectangle and
				// nothing else anywhere: no error, no console message, no clue
				// in the html. One reaches a developer with WP_DEBUG on, the
				// other reaches whoever has been asked to look at the page and
				// has view-source.
				$notices = $GLOBALS['slosm_stub']['doing_it_wrong'];

				assert_same( 1, count( $notices ), 'a locator rendered too late to load said nothing' );
				assert_same( 'Asymetria\\StoreLocator\\Assets::enqueue', $notices[0]['function'] );
				assert_contains( 'wp_footer()', $notices[0]['message'] );

				assert_contains( 'Store Locator: this locator was rendered after', $html );

				// The markup is still produced. A locator that threw away its
				// html because its script was late would turn a broken map into
				// a missing section of the page.
				assert_contains( '<div class="slosm" data-slosm="', $html );
			}
		);

		it(
			'says nothing of the kind on an ordinary page',
			function () {
				$render = slosm_assets_front_page();

				$html = $render( array() );

				// The control for the case above, and the one that matters: a
				// notice on every ordinary render would be worse than no notice
				// at all, because a log full of them is a log nobody reads.
				assert_same( array(), $GLOBALS['slosm_stub']['doing_it_wrong'] );
				assert_same( false, strpos( $html, 'rendered after' ) );
			}
		);

		it(
			'still renders its markup on a request where nothing registered the handles',
			function () {
				// A REST call asking for content.rendered runs the_content and
				// therefore this shortcode, and none of the front-end hooks fire
				// there. Neither does wp_footer, so nothing would print anyway —
				// what matters is that the render path does not raise a warning
				// or a fatal on the way. This case fires no hooks on purpose.
				slosm_assets_boot();

				$html = ( new Shortcode() )->render( array() );

				assert_contains( '<div class="slosm" data-slosm="', $html );

				// Nothing registered, so nothing reaches the queue: core files
				// the handle under $queued_before_register instead
				// (class-wp-dependencies.php lines 391-394) and releases it only
				// if something registers it later, which on this request nothing
				// will. An earlier version of this case asserted the handle was
				// queued, which was this suite's stub rather than WordPress.
				assert_same( array(), $GLOBALS['slosm_stub']['scripts'] );
				assert_same( array(), $GLOBALS['slosm_stub']['script_queue'] );

				// And nothing was attached, because there was nothing to attach
				// it to: WP_Scripts::add_data() returns false on its first line
				// for an unregistered handle (class-wp-scripts.php lines
				// 843-845), and wp_localize_script() is that call.
				assert_same( array(), slosm_assets_script( 'slosm-locator' )['l10n'] );

				// It is not even attempted. Every one of the strings is an
				// __() call, and on a site with a translation this plugin's .mo
				// is loaded just in time to answer the first of them — a file
				// read, on a REST request that will print none of it.
				assert_same( array(), $GLOBALS['slosm_stub']['localize_calls'] );
			}
		);
	}
);

describe(
	'the locator stylesheet arrives in two layers',
	function () {

		it(
			'registers the skin behind the layout, so the two cannot be applied in the wrong order',
			function () {
				slosm_assets_front_page();

				assert_same( 'slosm-locator-skin-css', Assets::STYLE_LOCATOR_SKIN );
				assert_same( SLOSM_URL . 'assets/css/locator-skin.css', slosm_assets_style( 'slosm-locator-skin-css' )['src'] );
				assert_same( SLOSM_VERSION, slosm_assets_style( 'slosm-locator-skin-css' )['ver'] );

				// Not decoration, the same way slosm-leaflet under slosm-locator
				// is not: all_deps() appends a handle's dependencies to the
				// to-do list before the handle itself, so declaring this is
				// what puts the skin *after* the layer it is allowed to
				// override. Two handles enqueued side by side would be printed
				// in queue order, which is the order of two calls in one method
				// rather than a fact anything asserts.
				assert_same(
					array( Assets::STYLE_LOCATOR ),
					slosm_assets_style( 'slosm-locator-skin-css' )['deps'],
					'the skin does not declare the layout as its dependency'
				);
			}
		);

		it(
			'ships the skin to a site that has said nothing about it',
			function () {
				$render = slosm_assets_front_page();

				$render( array() );

				assert_same(
					array( 'slosm-locator-css', 'slosm-locator-skin-css' ),
					$GLOBALS['slosm_stub']['style_queue'],
					'the default page did not get the skin'
				);
			}
		);

		it(
			'keeps the layout on a site that switched the skin off',
			function () {
				$render = slosm_assets_front_page();

				$GLOBALS['slosm_stub']['options'][ \Asymetria\StoreLocator\Settings::OPTION ] = array( 'skin' => false );

				$render( array() );

				/*
				 * The half of this that matters is the handle still in the
				 * queue. A single switch over the whole stylesheet would take
				 * the map's height floor with it, and a Leaflet container of
				 * zero height renders as nothing at all — so "I turned the
				 * styles off" and "the plugin is broken" would be the same
				 * screenshot. The layout layer is not the site's to lose.
				 */
				assert_same(
					array( 'slosm-locator-css' ),
					$GLOBALS['slosm_stub']['style_queue'],
					'switching the skin off either took the layout with it or left the skin on'
				);

				// Registered all the same. A site that wants the default look
				// back on one template can enqueue it by name, which is the
				// arrangement the cluster skin already has.
				assert_true(
					wp_style_is( 'slosm-locator-skin-css', 'registered' ),
					'the skin is not registered on a site that switched it off, so nothing can ask for it back'
				);
			}
		);
	}
);

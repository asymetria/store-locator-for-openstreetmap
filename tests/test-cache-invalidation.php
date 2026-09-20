<?php
/**
 * Proves the map payload is thrown away when a location changes, and only then.
 *
 * Two contracts, and they fail in opposite directions.
 *
 * A missed flush leaves every visitor looking at a map that is wrong for up to a
 * day — a branch that moved, a branch that closed, a category renamed. A flush
 * that fires when nothing changed throws the shared payload away while an editor
 * is simply typing, and every visitor in that window pays for a rebuild.
 *
 * Counted, never asserted
 * -----------------------
 * Every case here counts loader invocations, for the same reason
 * tests/test-repository-find-all.php does: a flush test that compares two
 * results passes perfectly with the invalidation deleted, because a rebuild
 * returns the same locations.
 *
 * There are two ways a case like this passes for the wrong reason, and they need
 * different guards, which is why there are two helpers rather than one:
 *
 * - A "flushes" case that passes because nothing was ever cached. Both helpers
 *   assert the fixture cached a payload before the event fires, so an empty
 *   cache is a failure rather than a silent pass.
 * - A "does not flush" case that passes because the hooks are not registered at
 *   all. That one the first guard cannot see, and it is not hypothetical:
 *   unregistering all six hooks in boot() leaves every negative case here green.
 *   So slosm_inval_ignores() fires a control event afterwards — one that must
 *   flush — and reports both numbers, and a negative case asserts the pair. A
 *   case that only ever proved "nothing happened" would be worthless the day the
 *   wiring broke.
 *
 * One request, one repository
 * ---------------------------
 * The repository memoises the payload for the life of the instance, so the
 * second request in each case is a second Store_Repository over the same loader.
 * One instance would answer from its own memo and prove nothing about the
 * transient. The plugin holds a repository of its own, which is the one the
 * hooks flush; the test never touches it, and does not need to — the generation
 * lives in an option, so an invalidation by one instance is visible to every
 * other.
 *
 * One boot is one request
 * -----------------------
 * Each case boots its own Plugin. That is not only about the singleton: a save
 * is its own request on a real site, and a case that fired four saves into one
 * boot would be asserting against the once-per-request guard rather than
 * against the hooks. The one case that deliberately fires several hooks into a
 * single boot is the one about that guard, and it says so.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-store.php';
require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
require_once dirname( __DIR__ ) . '/includes/class-store-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-plugin.php';
require_once dirname( __DIR__ ) . '/admin/class-settings-screen.php';
require_once dirname( __DIR__ ) . '/admin/class-shortcode-generator.php';
// boot() also builds a Rest_Controller to hang on rest_api_init, a
// Shortcode to hang on init and an Admin to hang on the metabox hooks, and
// this suite runs without the autoloader.
require_once dirname( __DIR__ ) . '/includes/class-geo.php';
require_once dirname( __DIR__ ) . '/includes/class-geocoder.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';
require_once dirname( __DIR__ ) . '/includes/class-assets.php';
require_once dirname( __DIR__ ) . '/includes/class-shortcode.php';
require_once dirname( __DIR__ ) . '/admin/class-admin.php';
require_once dirname( __DIR__ ) . '/admin/class-locations-list.php';
require_once dirname( __DIR__ ) . '/admin/class-bulk-geocode.php';

use Asymetria\StoreLocator\Plugin;
use Asymetria\StoreLocator\Store_Repository;

if ( ! function_exists( 'slosm_inval_row' ) ) {
	/**
	 * One row shaped the way get_posts() hands them over.
	 *
	 * Touches no stub state, so it is safe to call from anywhere.
	 *
	 * @param int    $id    Post id.
	 * @param string $title Post title.
	 * @return object
	 */
	function slosm_inval_row( int $id, string $title = 'Warszawa' ): object {
		return (object) array(
			'ID'           => $id,
			'post_title'   => $title,
			'post_content' => '',
		);
	}
}

if ( ! function_exists( 'slosm_inval_post' ) ) {
	/**
	 * One post object shaped the way the save and delete hooks hand them over.
	 *
	 * The post type is a literal rather than Post_Type::POST_TYPE, so a case
	 * about "some other post type" cannot accidentally agree with a change to
	 * the constant it is supposed to be distinguished from.
	 *
	 * @param int    $id     Post id.
	 * @param string $type   Post type.
	 * @param string $status Post status.
	 * @return object
	 */
	function slosm_inval_post( int $id, string $type = 'slosm_store', string $status = 'publish' ): object {
		return (object) array(
			'ID'          => $id,
			'post_type'   => $type,
			'post_status' => $status,
		);
	}
}

if ( ! function_exists( 'slosm_inval_wpml' ) ) {
	/**
	 * Answers the wpml_current_language filter with whatever a case staged.
	 *
	 * @param mixed $language Value being filtered.
	 * @return mixed
	 */
	function slosm_inval_wpml( $language ) {
		return $GLOBALS['slosm_stub']['inval_language'] ?? $language;
	}
}

if ( ! function_exists( 'slosm_inval_language' ) ) {
	/**
	 * Stages the language WPML would report for this request.
	 *
	 * A key of its own in the stub state rather than a row in its options array,
	 * which would have made this a real readable option inside the stub and
	 * something a future case could trip over with get_option(). it() replaces
	 * the whole state array between cases, so a key that is not in
	 * slosm_stub_defaults() is still cleared — and one set at file or group scope
	 * is still caught by the dirty check.
	 *
	 * @param string $language Language code.
	 * @return void
	 */
	function slosm_inval_language( string $language ): void {
		$GLOBALS['slosm_stub']['inval_language'] = $language;
	}
}

if ( ! function_exists( 'slosm_inval_located' ) ) {
	/**
	 * Stages the coordinates a location needs to reach the map payload.
	 *
	 * @param int $id Post id.
	 * @return void
	 */
	function slosm_inval_located( int $id ): void {
		update_post_meta( $id, '_slosm_lat', '52.2297' );
		update_post_meta( $id, '_slosm_lng', '21.0122' );
	}
}

if ( ! function_exists( 'slosm_inval_boot' ) ) {
	/**
	 * A freshly booted plugin, standing for one request.
	 *
	 * A fresh instance rather than Plugin::instance(), because the singleton
	 * boots at most once per process and every case after the first would then
	 * register no hooks at all — and every "does not flush" case would pass for
	 * that reason alone.
	 *
	 * Closures bound to the class scope rather than reflection:
	 * ReflectionProperty::setAccessible() is deprecated in PHP 8.5.
	 *
	 * @return Plugin
	 */
	function slosm_inval_boot(): Plugin {
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

if ( ! function_exists( 'slosm_inval_rebuilds' ) ) {
	/**
	 * Runs one request that caches the payload, fires an event, and reports.
	 *
	 * The return value is how many times the loader ran in total: 2 when the
	 * event invalidated the cache and the next request had to rebuild, 1 when it
	 * did not.
	 *
	 * The assertion in the middle is the reason this helper exists rather than
	 * being copied into every case: a "flushes" case whose fixture never cached
	 * anything passes with the flush deleted, and that becomes a failure here.
	 *
	 * For an event that must *not* flush, use slosm_inval_ignores() instead. This
	 * helper cannot tell "the guard turned the event away" from "boot() hooked
	 * nothing at all", and for a negative case that difference is the whole
	 * point.
	 *
	 * Stub state is written, so this may only be called from inside it().
	 *
	 * @param callable $fire Fires the WordPress hooks the event would fire.
	 * @return int Loader invocations after a second request asked for the list.
	 * @throws Assertion_Failed When the fixture did not cache a payload.
	 */
	function slosm_inval_rebuilds( callable $fire ): int {
		slosm_inval_located( 1 );

		$calls  = 0;
		$loader = function () use ( &$calls ) {
			$calls++;

			return array( slosm_inval_row( 1 ) );
		};

		( new Store_Repository( $loader ) )->find_all();

		if ( 1 !== $calls ) {
			throw new Assertion_Failed(
				'the fixture cached nothing, so neither a flush nor the absence of one could be proved'
			);
		}

		slosm_inval_boot();

		$fire();

		// A second repository over the same loader: the next request, which has
		// no memo and so can only be answered by the transient.
		( new Store_Repository( $loader ) )->find_all();

		return $calls;
	}
}

if ( ! function_exists( 'slosm_inval_ignores' ) ) {
	/**
	 * The same, for an event that must leave the cache alone.
	 *
	 * Returns two numbers: the loader invocations after the event, and then
	 * after a control event that is known to flush. A case asserts the pair,
	 * because the first number on its own is the one that passes when boot()
	 * registered nothing at all — and "nothing happened" is the expected result
	 * of a negative case and of a completely broken one alike.
	 *
	 * The control is a category rename, chosen because it needs no post, no
	 * taxonomy argument and no status: it cannot be turned away by any of the
	 * guards a negative case is exercising, so it fails only when the wiring
	 * itself is gone.
	 *
	 * Stub state is written, so this may only be called from inside it().
	 *
	 * @param callable $fire Fires the WordPress hooks the event would fire.
	 * @return int[] Loader invocations after the event, and after the control.
	 * @throws Assertion_Failed When the fixture did not cache a payload.
	 */
	function slosm_inval_ignores( callable $fire ): array {
		slosm_inval_located( 1 );

		$calls  = 0;
		$loader = function () use ( &$calls ) {
			$calls++;

			return array( slosm_inval_row( 1 ) );
		};

		( new Store_Repository( $loader ) )->find_all();

		if ( 1 !== $calls ) {
			throw new Assertion_Failed(
				'the fixture cached nothing, so the absence of a flush could not be proved'
			);
		}

		slosm_inval_boot();

		$fire();

		( new Store_Repository( $loader ) )->find_all();

		$after_event = $calls;

		do_action( 'edited_slosm_store_category', 5, 9 );

		( new Store_Repository( $loader ) )->find_all();

		return array( $after_event, $calls );
	}
}

describe(
	'cache generation',
	function () {

		it(
			'names the generation option after the plugin',
			function () {
				// The literal is written out here rather than read from the
				// class, because the uninstaller and the "clear cache" button
				// both have to name this option, and a test that reads the
				// constant agrees with any rename.
				assert_same( 'slosm_cache_generation', Store_Repository::GENERATION_OPTION );
			}
		);

		it(
			'carries a generation in the key, whatever the option holds',
			function () {
				update_option( 'locale', 'pl_PL' );

				// Never written. This is every site on the day it installs the
				// plugin, and the key it produces is the one most payloads will
				// ever be written under.
				assert_same( 'slosm_stores_lean_v1_g0_pl_pl', ( new Store_Repository() )->cache_key() );

				// What an importer, a half-finished migration or another plugin
				// on the same option name leaves behind. None of these may
				// collapse the segment and give two sites the same key for two
				// different generations.
				foreach ( array( '', 'nonsense', false, null, array() ) as $rubbish ) {
					update_option( 'slosm_cache_generation', $rubbish );

					assert_same(
						'slosm_stores_lean_v1_g0_pl_pl',
						( new Store_Repository() )->cache_key(),
						'a rubbish generation produced ' . ( new Store_Repository() )->cache_key()
					);
				}

				update_option( 'slosm_cache_generation', '7' );

				// A string is what get_option() hands back for a number it read
				// from the options table, so this is the ordinary case and not
				// the odd one.
				assert_same( 'slosm_stores_lean_v1_g7_pl_pl', ( new Store_Repository() )->cache_key() );
			}
		);

		it(
			'moves every language at once, which is the whole point of a generation',
			function () {
				add_filter( 'wpml_current_language', 'slosm_inval_wpml' );
				slosm_inval_located( 1 );

				$calls  = 0;
				$loader = function () use ( &$calls ) {
					$calls++;

					return array( slosm_inval_row( 1 ) );
				};

				slosm_inval_language( 'pl' );
				( new Store_Repository( $loader ) )->find_all();

				slosm_inval_language( 'en' );
				( new Store_Repository( $loader ) )->find_all();

				assert_same( 2, $calls, 'the two languages did not both cache a payload' );

				// The save happens in one language, as every save does.
				slosm_inval_language( 'pl' );
				slosm_inval_boot();
				do_action( 'save_post_slosm_store', 1, slosm_inval_post( 1 ), true );

				slosm_inval_language( 'pl' );
				( new Store_Repository( $loader ) )->find_all();

				assert_same( 3, $calls, 'the language the save ran in was not flushed' );

				// This is the assertion a delete_transient() of the current
				// language passes the case above and fails here: English was
				// never named by anything, and its payload still has to be
				// unreachable.
				slosm_inval_language( 'en' );
				( new Store_Repository( $loader ) )->find_all();

				assert_same( 4, $calls, 'a save in Polish left the English map stale' );
			}
		);

		it(
			'spends one generation on a save however many hooks it fires',
			function () {
				slosm_inval_located( 1 );

				$calls  = 0;
				$loader = function () use ( &$calls ) {
					$calls++;

					return array( slosm_inval_row( 1 ) );
				};

				( new Store_Repository( $loader ) )->find_all();

				slosm_inval_boot();

				// One editor pressing Update on one location: the terms are set,
				// the post is saved, and a term the save touched is updated.
				// Three generations here would be harmless and would also mean
				// the option is rewritten three times for every save on the site.
				do_action( 'set_object_terms', 1, array( 'Kawiarnia' ), array( 5 ), 'slosm_store_category', false, array() );
				do_action( 'save_post_slosm_store', 1, slosm_inval_post( 1 ), true );
				do_action( 'edited_slosm_store_category', 5, 9 );

				assert_same( 1, (int) get_option( 'slosm_cache_generation', 0 ) );

				// And the guard has to have cost nothing: the payload is still
				// unreachable, which is what the save was for.
				( new Store_Repository( $loader ) )->find_all();

				assert_same( 2, $calls, 'the guard swallowed the invalidation itself' );
			}
		);

		it(
			'leaves nothing reachable that was cached between the save and the meta write',
			function () {
				// The save hooks fire before the location is fully written. A
				// location's post row goes in first, then its terms, then its
				// registered meta — its coordinates among them — and on the block
				// editor's path WP_REST_Posts_Controller::update_item() does all
				// three in that order.
				//
				// So the flush a save fires is a flush of data that has not
				// changed yet, and the window it opens is the bug: a concurrent
				// front-end request landing inside it builds the payload from the
				// old coordinates and caches it under the generation the flush
				// just moved to. Nothing afterwards invalidates it, because the
				// save is over. Milliseconds of window, a full day of wrong pins,
				// and it cannot be seen from a single save on a quiet site.
				slosm_inval_located( 1 );

				$calls  = 0;
				$loader = function () use ( &$calls ) {
					$calls++;

					return array( slosm_inval_row( 1 ) );
				};

				// A visitor already has the map.
				( new Store_Repository( $loader ) )->find_all();

				slosm_inval_boot();

				// The post row is written, and the save hook fires.
				do_action( 'save_post_slosm_store', 1, slosm_inval_post( 1 ), true );

				// A concurrent request lands here, in the window, and caches the
				// coordinates as they were before the editor moved the branch.
				( new Store_Repository( $loader ) )->find_all();

				assert_same( 2, $calls, 'the concurrent request cached nothing, so there is no window to prove' );

				// Only now do the terms and the meta land. Neither has a hook of
				// its own, and the terms hook that does fire is swallowed by the
				// once-per-request guard, correctly.
				do_action( 'set_object_terms', 1, array( 'Kawiarnia' ), array( 5 ), 'slosm_store_category', false, array() );
				update_post_meta( 1, '_slosm_lat', '50.0647' );

				// And the save ends. wp_after_insert_post is the only thing that
				// fires after the data is actually there.
				do_action( 'wp_after_insert_post', 1, slosm_inval_post( 1 ), true, null );

				( new Store_Repository( $loader ) )->find_all();

				assert_same(
					3,
					$calls,
					'a payload built from pre-save coordinates was still reachable after the save finished'
				);
			}
		);

		it(
			'spends a second generation for the meta window, and only a second',
			function () {
				slosm_inval_located( 1 );

				$calls  = 0;
				$loader = function () use ( &$calls ) {
					$calls++;

					return array( slosm_inval_row( 1 ) );
				};

				( new Store_Repository( $loader ) )->find_all();

				slosm_inval_boot();

				// One save on the block editor's path, in the order WordPress
				// actually writes it. Four hooks; two generations, not four — the
				// one the save opens the window with, and the one that closes it.
				do_action( 'save_post_slosm_store', 1, slosm_inval_post( 1 ), true );
				do_action( 'set_object_terms', 1, array( 'Kawiarnia' ), array( 5 ), 'slosm_store_category', false, array() );
				do_action( 'edited_slosm_store_category', 5, 9 );
				do_action( 'wp_after_insert_post', 1, slosm_inval_post( 1 ), true, null );

				assert_same( 2, (int) get_option( 'slosm_cache_generation', 0 ) );
			}
		);

		it(
			'spends another generation once something has been cached under the last one',
			function () {
				slosm_inval_located( 1 );

				$calls = 0;
				$repo  = new Store_Repository(
					function () use ( &$calls ) {
						$calls++;

						return array( slosm_inval_row( 1 ) );
					}
				);

				$repo->find_all();
				$repo->flush_cache();

				assert_same( 1, (int) get_option( 'slosm_cache_generation', 0 ) );

				// Nothing has been rebuilt since, so there is nothing a second
				// generation would make unreachable.
				$repo->flush_cache();

				assert_same( 1, (int) get_option( 'slosm_cache_generation', 0 ) );

				// Now there is. A request that saves, renders the map and then
				// saves again has written a payload under the new generation,
				// and a guard that refused to move past it would serve that
				// payload — built before the second save — for a full day.
				$repo->find_all();

				assert_same( 2, $calls );

				$repo->flush_cache();

				assert_same( 2, (int) get_option( 'slosm_cache_generation', 0 ) );
			}
		);

		it(
			'spends another generation when the payload under it came from a concurrent request',
			function () {
				slosm_inval_located( 1 );

				$calls  = 0;
				$loader = function () use ( &$calls ) {
					$calls++;

					return array( slosm_inval_row( 1 ) );
				};

				$repo = new Store_Repository( $loader );
				$repo->flush_cache();

				assert_same( 1, (int) get_option( 'slosm_cache_generation', 0 ) );

				// Another request, on another PHP process, builds the payload and
				// caches it under the generation this one just moved to. Nothing
				// about it is this request's doing, and that is the point: the
				// guard's flag means "nothing is known to be cached", not "this
				// request has not cached anything".
				( new Store_Repository( $loader ) )->find_all();

				assert_same( 1, $calls );

				// This request then reads it — from the transient, since it has no
				// memo of its own yet.
				$repo->find_all();

				assert_same( 1, $calls, 'the read rebuilt instead of finding the concurrent payload' );

				// And saves again. That payload is exactly as stale now as one
				// this request had built itself, so the guard must let this
				// through.
				$repo->flush_cache();

				assert_same(
					2,
					(int) get_option( 'slosm_cache_generation', 0 ),
					'a payload read from a concurrent request was left reachable by the next save'
				);
			}
		);

		it(
			'says so when the generation cannot be moved, and can still recover',
			function () {
				slosm_inval_located( 1 );

				$failures = array();
				add_action(
					'slosm_cache_flush_failed',
					static function ( $option, $generation ) use ( &$failures ) {
						$failures[] = array( $option, $generation );
					}
				);

				$calls  = 0;
				$loader = function () use ( &$calls ) {
					$calls++;

					return array( slosm_inval_row( 1 ) );
				};

				$repo = new Store_Repository( $loader );
				$repo->find_all();

				// A read-only replica, a full disk, a crashed options table.
				// WordPress reports it as a plain false and says nothing more, and
				// the consequence here is worse than a failed transient write:
				// every payload on the site, in every language, stays reachable
				// and looks correct for a full day.
				$GLOBALS['slosm_stub']['option_failure'] = true;

				$repo->flush_cache();

				assert_same( 1, count( $failures ) );
				assert_same( 'slosm_cache_generation', $failures[0][0] );
				assert_same( 1, $failures[0][1] );

				// And the request must still be able to recover. Marking the
				// generation spent before knowing the write succeeded would mean
				// the guard swallowed every later flush in this request — so a
				// transient failure at the wrong moment would cost a full ttl of
				// stale maps rather than one retry.
				$GLOBALS['slosm_stub']['option_failure'] = false;

				$repo->flush_cache();

				assert_same( 1, (int) get_option( 'slosm_cache_generation', 0 ) );
				assert_same( 1, count( $failures ), 'the recovered flush reported a failure too' );

				( new Store_Repository( $loader ) )->find_all();

				assert_same( 2, $calls, 'the payload was still reachable after the retry' );
			}
		);

		it(
			'clears the memo even when the generation cannot move again',
			function () {
				slosm_inval_located( 1 );

				// This case has to be this convoluted, and the reason is worth
				// stating because the obvious version of it is worthless. A
				// find_all(), a flush_cache() and a find_all() on one instance
				// passes with the memo clearing deleted: the memo is keyed, the
				// generation has just changed the key, and the memo is therefore
				// already unreachable. The clearing only earns its line where the
				// generation stands still — and it stands still for a request
				// that has flushed once and cached nothing since.
				//
				// Which is exactly the site that cannot cache: an object cache
				// refusing an item over its size limit, roughly 4,000 locations
				// on Memcached's 1 MB default.
				$GLOBALS['slosm_stub']['transient_failure'] = true;

				$calls = 0;
				$repo  = new Store_Repository(
					function () use ( &$calls ) {
						$calls++;

						return array( slosm_inval_row( 1 ) );
					}
				);

				$repo->find_all();
				$repo->flush_cache();
				$repo->find_all();

				assert_same( 2, $calls, 'nothing rebuilt, so the memo below was never repopulated' );

				// Nothing has been cached since the first flush, so this one
				// spends no generation and the key does not move. The memo is all
				// that stands between this request and the list it built before
				// the second save — which on a save-then-render request is
				// precisely the data the editor just changed away from.
				$repo->flush_cache();
				$repo->find_all();

				assert_same( 3, $calls, 'flush_cache left the memo in place' );
			}
		);
	}
);

describe(
	'cache invalidation hooks',
	function () {

		it(
			'registers every hook boot() intends, exactly as often, and not again on a second boot',
			function () {
				$plugin = slosm_inval_boot();

				// Written out rather than derived, so that adding a hook is a
				// decision taken here as well as in boot(). Each row is the list
				// of registrations on that hook, as priority and accepted_args.
				//
				// The accepted_args are pinned because nothing else in this
				// suite can see them: the do_action() stub hands every callback
				// every argument regardless, so a hook registered for one
				// argument would guard on a $post it never receives and silently
				// stop flushing on a real site while every case below stayed
				// green.
				//
				// The priorities are pinned for a reason that arrived with Task
				// 17 and is worth stating, because the shape of this table
				// changed to hold it. save_post_slosm_store now carries two
				// callbacks: the metabox save at 9 and the flush at 10, in that
				// order and not the other. The metabox handler writes
				// coordinates — its own, and the geocoder's — so a flush running
				// first would throw the payload away and leave the save to make
				// it stale again a moment later, under a generation nothing will
				// move again. Swapping these two numbers is a silent failure
				// everywhere except here and in the ordering case in
				// tests/test-location-metabox.php.
				$expected = array(
					'save_post_slosm_store'       => array(
						array( 9, 2 ),
						array( 10, 2 ),
					),
					'wp_after_insert_post'        => array( array( 10, 2 ) ),
					'deleted_post'                => array( array( 10, 2 ) ),
					'set_object_terms'            => array( array( 10, 4 ) ),
					'deleted_term_relationships'  => array( array( 10, 3 ) ),
					'edited_slosm_store_category' => array( array( 10, 0 ) ),
					'delete_slosm_store_category' => array( array( 10, 0 ) ),
					'add_meta_boxes_slosm_store'  => array( array( 10, 1 ) ),
					// Task 18's picker, on the one admin hook that can reach the
					// location edit screen. One argument, because the hook
					// suffix is the only thing the hook passes and it is half of
					// what decides the screen; Assets::enqueue_admin() asks
					// get_current_screen() for the other half.
					'admin_enqueue_scripts'       => array( array( 10, 1 ) ),
					// Task 19's list table. Two arguments on the column
					// callback, because it is handed the column name and the
					// post id and either alone is useless.
					'manage_slosm_store_posts_custom_column' => array( array( 10, 2 ) ),
					// And the one hook in this whole table that fires on every
					// query on the site, the front end included. It is
					// registered unconditionally for the same reason
					// save_post_slosm_store is — one rule in one place rather
					// than a runtime condition in two — and
					// Locations_List::filter_query() turns away anything that is
					// not this screen's main admin query before it reads a
					// thing. There is a case for each way of not being it, in
					// tests/test-locations-list.php.
					'pre_get_posts'               => array( array( 10, 1 ) ),
					// Task 20. Two arguments on restrict_manage_posts, because
					// the hook passes the post type and which end of the table
					// it is printing, and the hidden input it prints must go on
					// neither another post type's screen nor twice on one form.
					'restrict_manage_posts'       => array( array( 10, 2 ) ),
					// Zero on the notice, because notice() declares no
					// parameters and admin_notices passes none.
					'admin_notices'               => array( array( 10, 0 ) ),
					// Task 21's settings screen. Two hooks rather than one,
					// because add_submenu_page() and add_settings_field() live
					// in different wp-admin includes and neither is loaded on a
					// front-end request; zero arguments on both, because neither
					// hook passes anything and neither method takes anything.
					// Two entries on admin_menu since Task 22: the settings
					// screen's add_page() and the shortcode generator's, in
					// that order. The generator has no admin_init twin because
					// it registers no settings and no admin_post twin because
					// it writes nothing — which is the whole shape of that
					// screen, asserted here rather than only in its own file.
					'admin_menu'                  => array( array( 10, 0 ), array( 10, 0 ) ),
					'admin_init'                  => array( array( 10, 0 ) ),
					// And the one hook in this plugin with a side effect behind
					// a credential of its own. admin-post.php checks nothing —
					// not a nonce, not a capability — so both are in
					// Settings_Screen::handle_clear_cache() and there are cases
					// for each in tests/test-settings-screen.php. The nopriv
					// twin is deliberately absent from this table: registering
					// it would be a logged-out request reaching a cache flush.
					'admin_post_slosm_clear_cache' => array( array( 10, 0 ) ),
				);

				$inventory = static function () use ( $expected ): array {
					$actual = array();

					foreach ( array_keys( $expected ) as $hook ) {
						$rows = array();

						foreach ( $GLOBALS['slosm_stub']['actions'][ $hook ] ?? array() as $registered ) {
							$rows[] = array( $registered['priority'], $registered['accepted_args'] );
						}

						// By priority, so the table above reads in running order
						// rather than in whatever order boot() happened to
						// register them.
						usort(
							$rows,
							static function ( array $a, array $b ): int {
								return $a[0] <=> $b[0];
							}
						);

						$actual[ $hook ] = $rows;
					}

					return $actual;
				};

				assert_same( $expected, $inventory() );

				$plugin->boot();

				assert_same( $expected, $inventory(), 'a second boot() registered hooks again' );

				// init carries more than one callback, so counting hook names
				// cannot see it change. Written out for the same reason the
				// table above is: a callback added to init — or one that
				// disappeared — would otherwise leave the inventory below
				// perfectly green, since 'init' is in it either way.
				$on_init = array();

				foreach ( $GLOBALS['slosm_stub']['actions']['init'] ?? array() as $registered ) {
					// One of the four is a static callback, so the first element
					// is a class name rather than an object. is_object() tells
					// them apart; get_class() on a string is a TypeError in PHP
					// 8, which would read as a broken test rather than as a
					// changed registration.
					$target    = $registered['callback'][0];
					$on_init[] = ( is_object( $target ) ? get_class( $target ) : (string) $target )
						. '::' . $registered['callback'][1];
				}

				// The order is asserted, not just the membership, and it is
				// load-bearing on one path: a theme or plugin that renders
				// content from inside its own init callback. All three are
				// hooked at priority 10 and run in registration order, so the
				// asset handles have to be declared before the shortcode that
				// enqueues them is even registered.
				// Task 21 added a fourth, and put it first rather than last. The
				// settings option's sanitiser is hung on
				// sanitize_option_slosm_settings by that call, and it is the
				// only thing between update_option() and the stored option — so
				// it goes up before anything else on init could write through
				// the door.
				// Task 23 added a fifth, and it is the only one on this hook
				// that is not at priority 10 — so the list below is the
				// registration order and not the running order, and the
				// priorities are asserted separately underneath. Bricks loads
				// the base class its element extends from its own init
				// callback at 10; registering alongside that is a race whose
				// losing branch is a fatal.
				assert_same(
					array(
						'Asymetria\\StoreLocator\\Settings::register',
						'Asymetria\\StoreLocator\\Post_Type::register',
						'Asymetria\\StoreLocator\\Assets::register',
						'Asymetria\\StoreLocator\\Shortcode::register',
						'Asymetria\\StoreLocator\\Plugin::register_bricks_element',
					),
					$on_init,
					'the callbacks on init are not the ones boot() is supposed to register, in that order'
				);

				// The priorities on init, in the same registration order. Four
				// tens and an eleven, and the eleven is the load-bearing one:
				// Bricks\Elements hooks the callback that require_once's
				// includes/elements/base.php on init at the default priority,
				// so \Bricks\Element does not exist until 10 has run and the
				// registration that requires a file extending it cannot share
				// that priority. Losing that race is a fatal on every request,
				// which is why the number is pinned here as well as in
				// tests/test-bricks-element.php.
				$init_priorities = array();

				foreach ( $GLOBALS['slosm_stub']['actions']['init'] ?? array() as $registered ) {
					$init_priorities[] = $registered['priority'];
				}

				assert_same(
					array( 10, 10, 10, 10, 11 ),
					$init_priorities,
					'the priorities on init are not the ones boot() is supposed to register'
				);

				// wp_enqueue_scripts is named here as a hook boot() must *not*
				// use, which is the opposite of the usual reason for a line in
				// this inventory. Registering the assets there is the arrangement
				// this plugin shipped first and had to withdraw: on a block
				// theme that hook fires after the content has already rendered,
				// so the interface strings were dropped in silence. The case is
				// in tests/test-assets.php; this line is what stops the hook
				// quietly coming back.
				assert_false(
					array_key_exists( 'wp_enqueue_scripts', $GLOBALS['slosm_stub']['actions'] ),
					'boot() hooked wp_enqueue_scripts, which fires after the content renders on every block theme'
				);

				// Nothing beyond this list, the post type and asset
				// registrations and the REST routes. A sweep over wp_options, or
				// an extra hook nobody justified, fails here rather than arriving
				// unnoticed — and 'rest_api_init' is named rather than skipped so
				// that boot() cannot grow another one without this case being
				// edited on purpose.
				$registered_hooks = array_keys( $GLOBALS['slosm_stub']['actions'] );
				$wanted           = array_merge( array_keys( $expected ), array( 'init', 'rest_api_init' ) );

				sort( $registered_hooks );
				sort( $wanted );

				assert_same( $wanted, $registered_hooks );

				// One filter, and exactly one. Task 18 marks the redirect of a
				// block-editor metabox save so that the render on the far side
				// of it can tell that nobody will ever read it; two arguments,
				// because redirect_post_location fires for every post type and
				// the post id is what says whether this redirect is a
				// location's. Admin::mark_discarded_render() has the trace.
				//
				// Written out rather than counted, for the same reason the
				// action table above is: a filter added to this plugin — a
				// content filter, a query filter, anything that runs on every
				// request on the site — has to be a deliberate edit to this
				// case rather than an arrival nobody noticed.
				$filters = array();

				foreach ( $GLOBALS['slosm_stub']['filters'] as $hook => $registrations ) {
					foreach ( $registrations as $registered ) {
						$filters[] = array( $hook, $registered['priority'], $registered['accepted_args'] );
					}
				}

				// Task 19 adds three more, and all three are dynamic names built
				// from Post_Type::POST_TYPE rather than typed: the generic
				// manage_posts_columns would put an Address column on every post
				// type on the site. Two of the three are the *screen* id and not
				// the post type — WP_List_Table::get_column_info() fires
				// manage_{$this->screen->id}_sortable_columns and
				// WP_List_Table::views() fires views_{$this->screen->id}, and a
				// list table's screen id is 'edit-' plus the post type. Written
				// with the post type alone they would be filters that never
				// fire, silently.
				sort( $filters );

				// Task 20 adds two more, and both are the screen id rather than
				// the post type for the same reason: WP_List_Table::bulk_actions()
				// fires bulk_actions-{$this->screen->id} and wp-admin/edit.php
				// fires handle_bulk_actions-{$screen} off
				// get_current_screen()->id. Three arguments on the handler,
				// because it is handed the redirect url, the action that was
				// chosen — this filter fires for every bulk action core does not
				// handle itself, so the name is what keeps it from acting — and
				// the ids.
				$wanted_filters = array(
					array( 'bulk_actions-edit-slosm_store', 10, 1 ),
					array( 'handle_bulk_actions-edit-slosm_store', 10, 3 ),
					array( 'manage_edit-slosm_store_sortable_columns', 10, 1 ),
					array( 'manage_slosm_store_posts_columns', 10, 1 ),
					array( 'redirect_post_location', 10, 2 ),
					array( 'views_edit-slosm_store', 10, 1 ),
				);

				sort( $wanted_filters );

				assert_same( $wanted_filters, $filters );
			}
		);

		it(
			'flushes when a location is published',
			function () {
				assert_same(
					2,
					slosm_inval_rebuilds(
						static function () {
							do_action( 'save_post_slosm_store', 1, slosm_inval_post( 1, 'slosm_store', 'publish' ), false );
						}
					)
				);
			}
		);

		it(
			'flushes when a location is updated',
			function () {
				assert_same(
					2,
					slosm_inval_rebuilds(
						static function () {
							do_action( 'save_post_slosm_store', 1, slosm_inval_post( 1, 'slosm_store', 'publish' ), true );
						}
					)
				);
			}
		);

		it(
			'flushes when a location is unpublished',
			function () {
				assert_same(
					2,
					slosm_inval_rebuilds(
						static function () {
							// A published location moved back to draft leaves the
							// map, so the payload it is in has to go.
							do_action( 'save_post_slosm_store', 1, slosm_inval_post( 1, 'slosm_store', 'draft' ), true );
						}
					)
				);
			}
		);

		it(
			'flushes when a location is trashed',
			function () {
				assert_same(
					2,
					slosm_inval_rebuilds(
						static function () {
							// Verified in WordPress 6.9.1: wp_trash_post() sets the
							// status with wp_update_post(), so save_post fires with
							// post_status 'trash' and no trashed_post hook is
							// needed. See Plugin::boot() for the caveat about
							// EMPTY_TRASH_DAYS.
							do_action( 'save_post_slosm_store', 1, slosm_inval_post( 1, 'slosm_store', 'trash' ), true );
						}
					)
				);
			}
		);

		it(
			'flushes when a location is untrashed',
			function () {
				assert_same(
					2,
					slosm_inval_rebuilds(
						static function () {
							// wp_untrash_post() also goes through wp_update_post(),
							// to 'draft' by default rather than back to the previous
							// status.
							do_action( 'save_post_slosm_store', 1, slosm_inval_post( 1, 'slosm_store', 'draft' ), true );
						}
					)
				);
			}
		);

		it(
			'flushes when a location is deleted for good',
			function () {
				assert_same(
					2,
					slosm_inval_rebuilds(
						static function () {
							// wp_delete_post() never calls wp_insert_post(), so no
							// save_post fires and this is the only hook that sees an
							// emptied trash.
							do_action( 'deleted_post', 1, slosm_inval_post( 1, 'slosm_store', 'trash' ) );
						}
					)
				);
			}
		);

		it(
			'flushes when categories are set on a location',
			function () {
				assert_same(
					2,
					slosm_inval_rebuilds(
						static function () {
							do_action( 'set_object_terms', 1, array( 'Kawiarnia' ), array( 5 ), 'slosm_store_category', false, array() );
						}
					)
				);
			}
		);

		it(
			'flushes when categories are removed from a location',
			function () {
				assert_same(
					2,
					slosm_inval_rebuilds(
						static function () {
							// wp_remove_object_terms() fires this and not
							// set_object_terms, so a plugin or an importer that
							// takes a category off a location is invisible without
							// it.
							do_action( 'deleted_term_relationships', 1, array( 5 ), 'slosm_store_category' );
						}
					)
				);
			}
		);

		it(
			'flushes when a category is renamed',
			function () {
				assert_same(
					2,
					slosm_inval_rebuilds(
						static function () {
							// The payload caches category names, not ids. A rename
							// that did not flush would leave every map on the site
							// showing the old name until the payload expired.
							do_action( 'edited_slosm_store_category', 5, 9 );
						}
					)
				);
			}
		);

		it(
			'flushes when a category is deleted',
			function () {
				assert_same(
					2,
					slosm_inval_rebuilds(
						static function () {
							do_action( 'delete_slosm_store_category', 5, 9, (object) array( 'term_id' => 5 ), array( 1 ) );
						}
					)
				);
			}
		);

		it(
			'does not flush for another post type',
			function () {
				assert_same(
					array( 1, 2 ),
					slosm_inval_ignores(
						static function () {
							// Every page and every post on the site would otherwise
							// throw the map away on save. The first of these is only
							// reached at all if boot() hooked the generic save_post,
							// which is the mistake; the second is reached either way
							// and has to be turned away on the post type.
							do_action( 'save_post_page', 2, slosm_inval_post( 2, 'page' ), true );
							do_action( 'save_post', 2, slosm_inval_post( 2, 'page' ), true );
							do_action( 'deleted_post', 2, slosm_inval_post( 2, 'page' ) );
							do_action( 'wp_after_insert_post', 2, slosm_inval_post( 2, 'page' ), true, null );
						}
					)
				);
			}
		);

		it(
			'does not flush for a revision, written or deleted',
			function () {
				assert_same(
					array( 1, 2 ),
					slosm_inval_ignores(
						static function () {
							// Both of these fire on an ordinary save, several times
							// over, and neither hook is type-specific.
							//
							// _wp_put_post_revision() leaves $fire_after_hooks at its
							// default, so every revision WordPress writes fires
							// wp_after_insert_post; and WordPress prunes old
							// revisions with wp_delete_post(), so every pruned one
							// fires deleted_post. Unguarded, a single save would
							// spend a generation per revision it touched.
							do_action( 'wp_after_insert_post', 99, slosm_inval_post( 99, 'revision', 'inherit' ), false, null );
							do_action( 'deleted_post', 99, slosm_inval_post( 99, 'revision', 'inherit' ) );
						}
					)
				);
			}
		);

		it(
			'does not flush for another taxonomy',
			function () {
				assert_same(
					array( 1, 2 ),
					slosm_inval_ignores(
						static function () {
							// Categories and tags are set on every post on the site.
							// The first two reach this plugin's callbacks and have to
							// be turned away on the taxonomy; the last two are only
							// reached if boot() hooked the untargeted edited_term and
							// delete_term.
							do_action( 'set_object_terms', 2, array( 'Nowości' ), array( 9 ), 'category', false, array() );
							do_action( 'deleted_term_relationships', 2, array( 9 ), 'post_tag' );
							do_action( 'edited_term', 9, 11, 'category', array() );
							do_action( 'delete_term', 9, 11, 'category', (object) array( 'term_id' => 9 ), array( 2 ) );
						}
					)
				);
			}
		);

		it(
			'does not flush while an auto-draft is being created',
			function () {
				assert_same(
					array( 1, 2 ),
					slosm_inval_ignores(
						static function () {
							// Opening Add New Location inserts an auto-draft, which
							// fires both of the save hooks. It is not on the map, was
							// never on the map, and cannot get there without a second
							// save that fires them again.
							do_action( 'save_post_slosm_store', 3, slosm_inval_post( 3, 'slosm_store', 'auto-draft' ), false );
							do_action( 'wp_after_insert_post', 3, slosm_inval_post( 3, 'slosm_store', 'auto-draft' ), false, null );
						}
					)
				);
			}
		);

		it(
			'does not flush for an autosave',
			function () {
				// A child process, because DOING_AUTOSAVE is a constant: defining
				// it here would define it for every case that runs afterwards and
				// quietly turn every flush above into a no-op. This is the same
				// reason tests/test-double-load.php runs a child.
				//
				// Autosaving a published location writes a revision, whose post
				// type is 'revision', so save_post_slosm_store never fires for it.
				// The case that needs guarding is the other one: for a draft owned
				// by the current user, wp_autosave() calls edit_post() and the
				// location itself is updated, with DOING_AUTOSAVE defined. An
				// unguarded hook then throws the shared payload away every few
				// seconds while an editor types.
				$root  = dirname( __DIR__ );
				$child = sprintf(
					'<?php' . "\n"
					. 'define( %s, true );' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					. 'require %s;' . "\n"
					// boot() also builds a Rest_Controller to hang on
					// rest_api_init, an Assets to hang on wp_enqueue_scripts, a
					// Shortcode to hang on init and an Admin to hang on the
					// metabox hooks, a Locations_List to hang on the list table's
					// and a Bulk_Geocode to hang on the bulk-action ones, and the
					// controller names Geo and the Geocoder, and a Settings_Screen
					// to hang on the admin hooks and a Shortcode_Generator to
					// hang on admin_menu; the child has no autoloader either.
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
					. '\\Asymetria\\StoreLocator\\Plugin::instance()->boot();' . "\n"
					. '$post = (object) array( %s => 1, %s => %s, %s => %s );' . "\n"
					. 'do_action( %s, 1, $post, true );' . "\n"
					. 'do_action( %s, 1, $post, true, null );' . "\n"
					. 'echo %s, var_export( get_option( %s, %s ), true ), %s;' . "\n"
					. 'do_action( %s, 5, 9 );' . "\n"
					. 'echo %s, var_export( get_option( %s, %s ), true );' . "\n",
					var_export( 'DOING_AUTOSAVE', true ),
					var_export( __DIR__ . '/bootstrap.php', true ),
					var_export( $root . '/includes/class-store.php', true ),
					var_export( $root . '/includes/class-post-type.php', true ),
					var_export( $root . '/includes/class-store-repository.php', true ),
					var_export( $root . '/includes/class-settings.php', true ),
					var_export( $root . '/includes/class-plugin.php', true ),
					var_export( $root . '/admin/class-settings-screen.php', true ),
					var_export( $root . '/includes/class-geo.php', true ),
					var_export( $root . '/includes/class-geocoder.php', true ),
					var_export( $root . '/includes/class-rest-controller.php', true ),
					var_export( $root . '/includes/class-assets.php', true ),
					var_export( $root . '/includes/class-shortcode.php', true ),
					var_export( $root . '/admin/class-admin.php', true ),
					var_export( $root . '/admin/class-locations-list.php', true ),
					var_export( $root . '/admin/class-bulk-geocode.php', true ),
					var_export( $root . '/admin/class-shortcode-generator.php', true ),
					var_export( 'ID', true ),
					var_export( 'post_type', true ),
					var_export( 'slosm_store', true ),
					var_export( 'post_status', true ),
					var_export( 'draft', true ),
					var_export( 'save_post_slosm_store', true ),
					var_export( 'wp_after_insert_post', true ),
					var_export( 'AFTER-AUTOSAVE=', true ),
					var_export( 'slosm_cache_generation', true ),
					var_export( 'unset', true ),
					var_export( ';', true ),
					var_export( 'edited_slosm_store_category', true ),
					var_export( 'AFTER-CONTROL=', true ),
					var_export( 'slosm_cache_generation', true ),
					var_export( 'unset', true )
				);

				// No .php suffix: the CLI binary runs a file whatever it is
				// called, and appending one would orphan the file tempnam()
				// itself created.
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

				assert_contains(
					"AFTER-AUTOSAVE='unset'",
					$output,
					"an autosave moved the cache generation; the child process said:\n" . trim( $output )
				);

				// Without this the case passes when the child dies on line one,
				// when boot() registers nothing and when the generation is never
				// written by anything at all.
				assert_contains(
					'AFTER-CONTROL=1',
					$output,
					"the control event did not move the generation either, so the case above proves nothing; the child process said:\n" . trim( $output )
				);
			}
		);
	}
);

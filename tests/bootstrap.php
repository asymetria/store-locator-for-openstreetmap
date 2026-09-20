<?php
/**
 * Stubs enough of WordPress to exercise plugin logic without an install.
 *
 * Every stub is deliberately dumb; it exists so the code under test can run,
 * not to emulate WordPress. State lives in $GLOBALS['slosm_stub'] and is reset
 * before every case by it(), so a test only has to set up what it cares about.
 *
 * Time and sleeping
 * -----------------
 * Every case starts on a fixed clock (SLOSM_STUB_EPOCH), not the real one.
 * slosm_stub_time() and slosm_stub_advance_time() give tests control of it, and
 * slosm_stub_sleep() records a sleep (into the 'sleeps' key) and advances that
 * clock instead of blocking. These are recorders only: no global
 * sleep seam is created here. Production code that needs a clock or a sleeper
 * — the Geocoder, for its 30-day cache and its one-request-per-second limit —
 * takes them as constructor arguments defaulting to microtime()/usleep(), the
 * same way Store_Repository takes its post loader. A test then injects
 * 'slosm_stub_time' and 'slosm_stub_sleep'.
 *
 * mbstring
 * --------
 * The LocalWP CLI binaries used to run this suite ship no mbstring and no
 * php.ini, so mb_* calls are a fatal error here. Production code must call
 * mb_strtolower() only when function_exists() says it is there and fall back to
 * strtolower(). That is not a test workaround: mbstring is not guaranteed on a
 * WordPress host either, and ASCII-folding a query like "Łódź" costs a cache
 * miss, not a wrong answer.
 *
 * Testing that something was NOT cached
 * -------------------------------------
 * get_transient() returns false both for a miss and for a stored false, exactly
 * as WordPress does, and the stub will not pretend otherwise. So a test that
 * proves the Geocoder declined to cache a failure — a 429, say — must assert on
 * array_key_exists( $key, $GLOBALS['slosm_stub']['transients'] ); only that can
 * tell "correctly did not cache" from "cached false for thirty days".
 *
 * @package Store_Locator_For_OpenStreetMap
 */

if ( ! defined( 'SLOSM_STUB_EPOCH' ) ) {
	/**
	 * The clock every case starts on: 2026-01-01 00:00:00 UTC.
	 *
	 * Fixed rather than real, so an elapsed-time assertion reads 1.0 instead of
	 * 0.9999987 and never fails intermittently.
	 */
	define( 'SLOSM_STUB_EPOCH', 1767225600.0 );
}

if ( ! function_exists( 'slosm_stub_defaults' ) ) {
	/**
	 * The empty stub state. One definition, used by the initialiser and the reset.
	 *
	 * @return array
	 */
	function slosm_stub_defaults(): array {
		return array(
			'transients'        => array(),
			'transient_failure' => false, // Makes set_transient() fail the way a full object cache does.
			'options'           => array(),
			'option_autoload'   => array(), // The autoload flag each update_option() call passed.
			'option_failure'    => false, // Makes update_option() fail the way a read-only or full database does.
			'actions'           => array(),
			'filters'           => array(),
			'post_types'        => array(), // Calls to register_post_type().
			'taxonomies'        => array(), // Calls to register_taxonomy().
			'http_queue'        => array(), // Responses waiting to be handed to wp_remote_get().
			'http_requests'     => array(), // Urls and args actually requested.
			'rest_routes'       => array(), // Calls to register_rest_route().
			'post_meta'         => array(),
			'posts'             => array(), // Rows get_posts() hands back, whatever it was asked for.
			'posts_by_id'       => array(), // Rows get_post() hands back, keyed by post id.
			'post_queries'      => array(), // Argument arrays actually passed to get_posts().
			'object_terms'      => array(), // Term names per object id, or a WP_Error.
			'term_requests'     => array(), // Object ids and taxonomies actually asked for.
			'terms'             => array(), // Term rows per taxonomy, for get_term_by().
			'term_lookups'      => array(), // Field, value and taxonomy actually asked of get_term_by().
			'shortcodes'        => array(), // Calls to add_shortcode(), appended per tag.
			'post_counts'       => array(), // Status counts per post type, for wp_count_posts().
			'scripts'           => array(), // Registered scripts, keyed by handle: src, deps, ver, extra.
			'styles'            => array(), // Registered styles, keyed by handle: src, deps, ver, media.
			'script_queue'      => array(), // Handles wp_enqueue_script() put in the queue, in order.
			'style_queue'       => array(), // Handles wp_enqueue_style() put in the queue, in order.
			'script_early'      => array(), // Scripts enqueued before registration; core's queued_before_register.
			'style_early'       => array(), // Styles enqueued before registration; core's queued_before_register.
			'script_args'       => array(), // The raw fifth argument of every wp_register_script() call, per handle.
			'localize_calls'    => array(), // Every wp_localize_script() call, whether or not it attached.
			'translation_calls' => array(), // Every wp_set_script_translations() call.
			'action_counts'     => array(), // How many times each hook has been fired, for did_action().
			'doing_it_wrong'    => array(), // Every _doing_it_wrong() call: function, message, version.
			'sleeps'            => array(), // Seconds passed to slosm_stub_sleep().
			'meta_boxes'        => array(), // Calls to add_meta_box().
			'capabilities'      => array(), // What current_user_can() says yes to; empty means no.
			'cap_checks'        => array(), // Every current_user_can() call: capability and arguments.
			'current_user_id'   => 0, // What get_current_user_id() answers; 0 is logged out.
			'current_screen'    => null, // What get_current_screen() answers; null is "no screen", which is a real state.
			'is_admin'          => false, // What is_admin() answers; false is the front end, which is most requests.

			// Task 21's admin-menu and Settings-API recorders; see the block at
			// the foot of this file for what each one models and what it does not.
			'admin_pages'       => array(), // Calls to add_submenu_page(), with the hook suffix each answered with.
			'settings'          => array(), // Calls to register_setting().
			'settings_sections' => array(), // Sections per page, for do_settings_sections().
			'settings_field_list' => array(), // Fields per page and section; named so it cannot be read as settings_fields().
			'redirects'         => array(), // Calls to wp_safe_redirect().
			'died'              => array(), // Calls to wp_die(), each of which also threw.

			// Task 25's uninstall state; see the block at the foot of this file
			// for what each one models and, more importantly, what it does not.
			'options_deleted'   => array(), // Names passed to delete_option(), in order, across every site.
			'db_options'        => array(), // Raw wp_options rows for the current site, as SQL sees them.
			'db_queries'        => array(), // Every string handed to $wpdb->query(), across every site.
			'is_multisite'      => false, // What is_multisite() answers.
			'current_blog_id'   => 1, // What get_current_blog_id() answers, and which options table $wpdb names.
			'blogs'             => array(), // Per-site stores for every site not currently switched to.
			'blog_stack'        => array(), // Site ids switch_to_blog() has pushed, innermost last.
			'blog_switches'     => array(), // Every id switch_to_blog() was called with, in order.
			'blog_restores'     => 0, // How many times restore_current_blog() has been called.
			'sites'             => array(), // Site ids get_sites() has to hand out.
			'site_queries'      => array(), // Argument arrays actually passed to get_sites().
			'large_network'     => false, // What wp_is_large_network() answers.
			'large_network_checks' => array(), // The $using argument of every wp_is_large_network() call.
			'option_log'        => array(), // Reads and deletes in the order they happened: 'read:name', 'delete:name'.
			'deleted_posts'     => array(), // Every wp_delete_post() call: id and whether it was forced.
			'deleted_terms'     => array(), // Every wp_delete_term() call: term id and taxonomy.
			'term_queries'      => array(), // Argument arrays actually passed to get_terms().
			'undeletable_posts' => array(), // Ids wp_delete_post() refuses, the way a pre_delete_post filter does.

			// Task 23. Calls to Bricks\Elements::register_element(), recorded
			// by tests/bricks-registry-stub.php; see that file for what of the
			// real registry is modelled and what is not.
			'bricks_elements'   => array(),

			// What __() answers for a given source string, keyed by that
			// string. Empty means "this site has no translation file", which
			// is what every case that does not set one gets.
			//
			// It exists because a .mo file is *content*: a site owner
			// installs a language pack, and everything __() returns
			// afterwards came out of a file they did not write. Without a way
			// to say that in a case, every escaping decision on a translated
			// string is unfalsifiable here — esc_html() is the identity on
			// every English string this plugin ships, so esc_html__() and
			// __() are the same function as far as a suite with no
			// translations is concerned. That was a real gap: the escaping on
			// the Bricks element's panel label survived being deleted.
			'translations'      => array(),

			'time'              => SLOSM_STUB_EPOCH,
		);
	}
}

$GLOBALS['slosm_stub'] = slosm_stub_defaults();

if ( ! function_exists( 'slosm_stub_reset' ) ) {
	/**
	 * Resets every piece of stub state.
	 *
	 * @return void
	 */
	function slosm_stub_reset(): void {
		$GLOBALS['slosm_stub'] = slosm_stub_defaults();
	}
}

if ( ! function_exists( 'slosm_stub_time' ) ) {
	/**
	 * The current time as the stubs see it.
	 *
	 * @return float SLOSM_STUB_EPOCH plus whatever the test has advanced.
	 */
	function slosm_stub_time(): float {
		return (float) $GLOBALS['slosm_stub']['time'];
	}
}

if ( ! function_exists( 'slosm_stub_advance_time' ) ) {
	/**
	 * Moves the stub clock forward.
	 *
	 * @param float $seconds Seconds to advance.
	 * @return void
	 */
	function slosm_stub_advance_time( float $seconds ): void {
		$GLOBALS['slosm_stub']['time'] = slosm_stub_time() + $seconds;
	}
}

if ( ! function_exists( 'slosm_stub_sleep' ) ) {
	/**
	 * Records a sleep and advances the stub clock instead of blocking.
	 *
	 * Inject this where production code would sleep; see the file header.
	 *
	 * @param float $seconds Seconds the code asked to sleep for.
	 * @return void
	 */
	function slosm_stub_sleep( float $seconds ): void {
		$GLOBALS['slosm_stub']['sleeps'][] = $seconds;
		slosm_stub_advance_time( $seconds );
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'MONTH_IN_SECONDS' ) ) {
	define( 'MONTH_IN_SECONDS', 2592000 );
}

/*
 * The three plugin constants the code under test reads.
 *
 * On a real site the main plugin file defines these before anything can load a
 * class, and the autoloader refuses to run at all without SLOSM_DIR — which is
 * defined in the same block — so production code reaching them undefined is not
 * a state a site can be in. The suite does not load the main plugin file, so
 * they are defined here instead of the code under test being written around
 * their absence.
 *
 * The version is deliberately not '1.0.0'. A test that asserts a handle carries
 * SLOSM_VERSION has to fail when the handle carries a hard-coded version string
 * that happens to match the plugin header today, and an unreal value is what
 * makes that visible in the failure output rather than plausible.
 */
if ( ! defined( 'SLOSM_VERSION' ) ) {
	define( 'SLOSM_VERSION', '9.9.9-stub' );
}
if ( ! defined( 'SLOSM_URL' ) ) {
	define( 'SLOSM_URL', 'https://example.test/wp-content/plugins/store-locator-for-openstreetmap/' );
}
/*
 * The real plugin root, and the one of the three that is not a stub value.
 *
 * Task 23 needs it real. Plugin::register_bricks_element() hands Bricks an
 * absolute path to a file Bricks will require, and a case asserts that path is
 * readable — which is the whole point of the case, since a path that is right
 * in shape and wrong on disk is a fatal inside somebody else's theme.
 *
 * Trailing slash, because plugin_dir_path() leaves one and Autoloader::load()
 * concatenates against it without adding one.
 */
if ( ! defined( 'SLOSM_DIR' ) ) {
	define( 'SLOSM_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Records an action registration.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $cb            Callback.
	 * @param int      $priority      Recorded and honoured: do_action() runs callbacks in priority order.
	 * @param int      $accepted_args Recorded, not honoured: do_action() hands every callback every argument.
	 * @return bool
	 */
	function add_action( $hook, $cb, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['slosm_stub']['actions'][ $hook ][] = array(
			'callback'      => $cb,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Records a filter registration.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $cb            Callback.
	 * @param int      $priority      Recorded and honoured: apply_filters() runs callbacks in priority order.
	 * @param int      $accepted_args Recorded, not honoured: apply_filters() hands every callback every argument.
	 * @return bool
	 */
	function add_filter( $hook, $cb, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['slosm_stub']['filters'][ $hook ][] = array(
			'callback'      => $cb,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Runs every registered filter callback in priority order.
	 *
	 * See slosm_stub_by_priority() for why that is priority order rather than
	 * registration order.
	 *
	 * @param string $hook    Hook name.
	 * @param mixed  $value   Value to filter.
	 * @param mixed  ...$rest Extra arguments.
	 * @return mixed
	 */
	function apply_filters( $hook, $value, ...$rest ) {
		foreach ( slosm_stub_by_priority( 'filters', $hook ) as $registered ) {
			$value = $registered['callback']( $value, ...$rest );
		}
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Runs every registered action callback in registration order.
	 *
	 * The counter is incremented before a single callback runs, and that
	 * ordering is copied from core rather than chosen: wp-includes/plugin.php
	 * lines 490-494 of 6.9.1 raise $wp_actions[ $hook ] and only then reach the
	 * callbacks at line 497. It is what makes did_action( 'init' ) already 1
	 * inside an init callback, which is in turn what lets this plugin register
	 * scripts on init without _wp_scripts_maybe_doing_it_wrong() scolding it.
	 * A stub that counted afterwards would make that arrangement look wrong.
	 *
	 * @param string $hook    Hook name.
	 * @param mixed  ...$args Arguments passed to the callbacks.
	 * @return void
	 */
	function do_action( $hook, ...$args ) {
		$GLOBALS['slosm_stub']['action_counts'][ $hook ] = ( $GLOBALS['slosm_stub']['action_counts'][ $hook ] ?? 0 ) + 1;

		foreach ( slosm_stub_by_priority( 'actions', $hook ) as $registered ) {
			$registered['callback']( ...$args );
		}
	}
}

if ( ! function_exists( 'slosm_stub_by_priority' ) ) {
	/**
	 * The callbacks on one hook, in the order WordPress would run them.
	 *
	 * Priority first, registration order within a priority — which is what
	 * WP_Hook does and what an earlier version of these stubs did not. That
	 * version ran callbacks in registration order alone and said so in two
	 * docblocks, and the gap was invisible because nothing in the suite had ever
	 * registered anything at a priority other than 10.
	 *
	 * Task 17 is where it stopped being invisible. The metabox save handler is
	 * registered on save_post_slosm_store at priority 9 specifically so that it
	 * writes its meta *before* Task 8's flush runs at 10; under the old stub the
	 * flush ran first, because boot() registers it first, and the one ordering
	 * the handler exists to guarantee was the one ordering no case could see.
	 *
	 * Verified against WordPress 6.9.1: WP_Hook::add_filter() ends with
	 * ksort( $this->callbacks, SORT_NUMERIC ) at wp-includes/class-wp-hook.php
	 * line 98, and the array under each priority keeps insertion order. PHP's
	 * sort has been stable since 8.0, so usort() here reproduces both halves.
	 *
	 * Still not modelled: removal mid-iteration, nested do_action() re-sorting,
	 * and the $wp_filter globals themselves. Nothing here needs them.
	 *
	 * @param string $kind 'actions' or 'filters'.
	 * @param string $hook Hook name.
	 * @return array[] Registrations, in running order.
	 */
	function slosm_stub_by_priority( string $kind, string $hook ): array {
		$registered = $GLOBALS['slosm_stub'][ $kind ][ $hook ] ?? array();

		usort(
			$registered,
			static function ( array $a, array $b ): int {
				return (int) $a['priority'] <=> (int) $b['priority'];
			}
		);

		return $registered;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	/**
	 * How many times a hook has been fired.
	 *
	 * @param string $hook Hook name.
	 * @return int
	 */
	function did_action( $hook ) {
		return (int) ( $GLOBALS['slosm_stub']['action_counts'][ $hook ] ?? 0 );
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	/**
	 * Records a misuse notice.
	 *
	 * Deliberately quieter than WordPress, and the reason is the framework
	 * rather than convenience. Core routes this through wp_trigger_error(),
	 * which raises E_USER_WARNING when WP_DEBUG is on, and tests/framework.php
	 * fails any case that raises a warning — so a stub that warned would make a
	 * case asserting "this plugin correctly complained" impossible to write. It
	 * is recorded instead, which is strictly more permissive than core, and a
	 * case that cares has to read the recording.
	 *
	 * The doing_it_wrong_run action fires either way, because core fires it
	 * whether or not WP_DEBUG is on and something may be listening.
	 *
	 * @param string $function_name Function that was misused.
	 * @param string $message       What was wrong.
	 * @param string $version       Version the notice was added in.
	 * @return void
	 */
	function _doing_it_wrong( $function_name, $message, $version ) {
		$GLOBALS['slosm_stub']['doing_it_wrong'][] = array(
			'function' => $function_name,
			'message'  => $message,
			'version'  => $version,
		);

		do_action( 'doing_it_wrong_run', $function_name, $message, $version );
	}
}

if ( ! function_exists( 'register_post_type' ) ) {
	/**
	 * Records a post type registration.
	 *
	 * A recorder, not an implementation: nothing here validates the name, fills
	 * in WordPress's own defaults or builds a WP_Post_Type. Tests assert on the
	 * arguments the plugin actually handed to WordPress, which is the only part
	 * the plugin controls. Every call is appended rather than keyed by name, so
	 * a test can tell one registration from two of the same post type.
	 *
	 * @param string $post_type Post type key.
	 * @param array  $args      Registration arguments, recorded verbatim.
	 * @return bool
	 */
	function register_post_type( $post_type, $args = array() ) {
		$GLOBALS['slosm_stub']['post_types'][] = array(
			'name' => $post_type,
			'args' => $args,
		);
		return true;
	}
}

if ( ! function_exists( 'register_taxonomy' ) ) {
	/**
	 * Records a taxonomy registration.
	 *
	 * The object type is kept exactly as it was passed, string or array, because
	 * which post types a taxonomy is attached to is part of what a test needs to
	 * prove. Appended rather than keyed, for the same reason as post types.
	 *
	 * @param string       $taxonomy    Taxonomy key.
	 * @param array|string $object_type Post type, or post types, the taxonomy is attached to.
	 * @param array        $args        Registration arguments, recorded verbatim.
	 * @return bool
	 */
	function register_taxonomy( $taxonomy, $object_type, $args = array() ) {
		$GLOBALS['slosm_stub']['taxonomies'][] = array(
			'name'        => $taxonomy,
			'object_type' => $object_type,
			'args'        => $args,
		);
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Reads a stubbed transient, honouring its lifetime against the stub clock.
	 *
	 * @param string $key Transient key.
	 * @return mixed False when unset or expired.
	 */
	function get_transient( $key ) {
		$stored = $GLOBALS['slosm_stub']['transients'][ $key ] ?? null;

		if ( null === $stored ) {
			return false;
		}

		if ( 0 < $stored['expires'] && slosm_stub_time() >= $stored['expires'] ) {
			return false;
		}

		return $stored['value'];
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Writes a stubbed transient, recording the lifetime it was asked for.
	 *
	 * Tests read the recorded ttl straight out of the state array; the expiry is
	 * there so a test can advance the clock past it.
	 *
	 * Setting the 'transient_failure' key makes every write fail and store
	 * nothing, which is what an object cache does with an item over its size
	 * limit — Memcached's default is 1 MB. WordPress reports that as a plain
	 * false from set_transient() and says nothing else, so code that ignores the
	 * return simply rebuilds forever; this knob is how a test can tell whether it
	 * is ignored.
	 *
	 * @param string $key   Transient key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   Lifetime in seconds; 0 means no expiry, negative is already expired.
	 * @return bool
	 */
	function set_transient( $key, $value, $ttl = 0 ) {
		if ( ! empty( $GLOBALS['slosm_stub']['transient_failure'] ) ) {
			return false;
		}

		if ( 0 < $ttl ) {
			$expires = slosm_stub_time() + $ttl;
		} elseif ( 0 > $ttl ) {
			$expires = slosm_stub_time(); // Expired on arrival, as WordPress treats a past expiry.
		} else {
			$expires = 0; // No expiry.
		}

		$GLOBALS['slosm_stub']['transients'][ $key ] = array(
			'value'   => $value,
			'ttl'     => $ttl,
			'expires' => $expires,
		);
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Deletes a stubbed transient.
	 *
	 * @param string $key Transient key.
	 * @return bool
	 */
	function delete_transient( $key ) {
		unset( $GLOBALS['slosm_stub']['transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Reads a stubbed option, and records that it was read.
	 *
	 * The recording is Task 25's, and it is the only way one question can be
	 * asked: was this option *read before it was deleted*. The uninstaller has
	 * to take one flag out of slosm_settings and then delete slosm_settings,
	 * and both orders leave identical wreckage behind — the option is gone
	 * either way, and the branch simply never runs. Only the order tells them
	 * apart, so the order has to be visible.
	 *
	 * One log for reads and deletes together, rather than two lists that would
	 * have to be interleaved by guesswork afterwards.
	 *
	 * @param string $key     Option name.
	 * @param mixed  $default Returned when unset.
	 * @return mixed
	 */
	function get_option( $key, $default = false ) {
		$GLOBALS['slosm_stub']['option_log'][] = 'read:' . $key;

		return $GLOBALS['slosm_stub']['options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Writes a stubbed option.
	 *
	 * Setting the 'option_failure' key makes every write fail and store nothing,
	 * which is what a read-only replica, a full disk or a crashed options table
	 * does. WordPress reports that as a plain false and says nothing else, so
	 * code that ignores the return believes it wrote; this knob is how a test can
	 * tell whether it is ignored.
	 *
	 * What is deliberately not modelled is WordPress's *benign* false — the one
	 * returned when the new value equals the old. Nothing here needs it: the only
	 * option this plugin writes is a counter that always increments.
	 *
	 * The autoload flag is recorded rather than honoured, into 'option_autoload'.
	 * The stub has no alloptions to put anything in, so honouring it would mean
	 * nothing — but the flag is a real decision with a real cost, since an
	 * autoloaded option is fetched on every request on the site whether or not
	 * anything wants it. Recording it is what lets a test assert the decision
	 * instead of taking the docblock's word for it.
	 *
	 * @param string $key      Option name.
	 * @param mixed  $value    Value.
	 * @param mixed  $autoload Recorded, not honoured; see above.
	 * @return bool
	 */
	function update_option( $key, $value, $autoload = null ) {
		if ( ! empty( $GLOBALS['slosm_stub']['option_failure'] ) ) {
			return false;
		}

		$GLOBALS['slosm_stub']['options'][ $key ]         = $value;
		$GLOBALS['slosm_stub']['option_autoload'][ $key ] = $autoload;
		return true;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Records the request and returns the next queued response.
	 *
	 * There is no fallback response on purpose. A 200 with an empty body is a
	 * meaningful answer to a geocoding call ("worked, no matches"), so inventing
	 * one would hide both an unexpected request and an extra request that a
	 * caching bug produced.
	 *
	 * @param string $url  Requested url.
	 * @param array  $args Request arguments.
	 * @return mixed The next queued response.
	 * @throws RuntimeException When no response is queued.
	 */
	function wp_remote_get( $url, $args = array() ) {
		$GLOBALS['slosm_stub']['http_requests'][] = array(
			'url'  => $url,
			'args' => $args,
		);

		if ( empty( $GLOBALS['slosm_stub']['http_queue'] ) ) {
			throw new RuntimeException(
				'unqueued wp_remote_get() request to ' . $url
				. " — push a response onto \$GLOBALS['slosm_stub']['http_queue'] first"
			);
		}

		return array_shift( $GLOBALS['slosm_stub']['http_queue'] );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Reads the body out of a stubbed response.
	 *
	 * @param mixed $response Response.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) ? ( $response['body'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Reads the status code out of a stubbed response.
	 *
	 * @param mixed $response Response.
	 * @return int
	 */
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Tells whether a value is a WP_Error.
	 *
	 * @param mixed $thing Value to check.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal stand-in for the WordPress error object. One code, no lists.
	 */
	class WP_Error {

		/**
		 * Error code.
		 *
		 * @var string
		 */
		public $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		public $message;

		/**
		 * Error data, such as array( 'status' => 404 ) for a REST response.
		 *
		 * @var mixed
		 */
		public $data;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * Returns the error message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * Returns the error code, or an empty string for an empty error.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return '' === $this->code || null === $this->code ? '' : $this->code;
		}

		/**
		 * Returns the error data.
		 *
		 * @return mixed Null when none was supplied.
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Strips markup and collapses whitespace, following core's chain in order:
	 * wp_pre_kses_less_than(), wp_strip_all_tags(), whitespace collapse, then
	 * percent-octet removal.
	 *
	 * The first step is the subtle one: a "<" with no matching ">" is text, not
	 * a tag, so it is escaped instead of being handed to strip_tags(), which
	 * would delete it along with everything after it. "Bar <Pub" keeps its name.
	 *
	 * Not ported: wp_check_invalid_utf8() and null-byte stripping.
	 *
	 * @param mixed $str Value to sanitize.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return slosm_stub_sanitize_text_fields( $str, false );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * The same, keeping the newlines.
	 *
	 * Core's _sanitize_text_fields() takes a $keep_newlines flag and
	 * sanitize_textarea_field() passes true; verified in WordPress 6.9.1,
	 * wp-includes/formatting.php lines 5618 and 5663. The difference is one
	 * preg_replace, and it is the whole reason the opening-hours field cannot
	 * go through sanitize_text_field(): that one collapses every newline in a
	 * week's opening hours into a single line of text.
	 *
	 * @param mixed $str Value to sanitize.
	 * @return string
	 */
	function sanitize_textarea_field( $str ) {
		return slosm_stub_sanitize_text_fields( $str, true );
	}
}

if ( ! function_exists( 'slosm_stub_sanitize_text_fields' ) ) {
	/**
	 * Core's _sanitize_text_fields(), as far as this suite models it.
	 *
	 * The chain, in order: wp_pre_kses_less_than(), wp_strip_all_tags(), the
	 * whitespace collapse (skipped when newlines are kept), then percent-octet
	 * removal.
	 *
	 * The first step is the subtle one: a "<" with no matching ">" is text, not
	 * a tag, so it is escaped instead of being handed to strip_tags(), which
	 * would delete it along with everything after it. "Bar <Pub" keeps its name.
	 *
	 * The percent-octet removal is the step that matters most to Task 17, and
	 * it matters by being a reason *not* to call this: a url with a %20 in it
	 * comes out of here with the escape deleted, so the website field is
	 * sanitised by esc_url_raw() alone and never by this.
	 *
	 * Not ported: wp_check_invalid_utf8() and null-byte stripping.
	 *
	 * @param mixed $str           Value to sanitize.
	 * @param bool  $keep_newlines Whether newlines survive.
	 * @return string
	 */
	function slosm_stub_sanitize_text_fields( $str, bool $keep_newlines ) {
		if ( is_array( $str ) || is_object( $str ) ) {
			return '';
		}

		$filtered = (string) $str;

		if ( false !== strpos( $filtered, '<' ) ) {
			$filtered = preg_replace_callback(
				'%<[^>]*?((?=<)|>|$)%',
				static function ( $matches ) {
					return false === strpos( $matches[0], '>' ) ? esc_html( $matches[0] ) : $matches[0];
				},
				$filtered
			);

			$filtered = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $filtered );
			$filtered = strip_tags( $filtered );

			// Keep a stray "<" from meeting the next line and forming a tag.
			$filtered = str_replace( "<\n", "&lt;\n", $filtered );
		}

		if ( ! $keep_newlines ) {
			$filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
		}

		$filtered = trim( $filtered );

		$found = false;
		while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
			$filtered = str_replace( $match[0], '', $filtered );
			$found    = true;
		}

		return $found ? trim( preg_replace( '/ +/', ' ', $filtered ) ) : $filtered;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Reduces a string to lowercase alphanumerics, dashes and underscores.
	 *
	 * @param mixed $str Value to sanitize.
	 * @return string
	 */
	function sanitize_key( $str ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $str ) );
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	/**
	 * Cleans one css class, the way core does.
	 *
	 * Here for the same reason sanitize_hex_color() below is: nothing in this
	 * plugin calls it. Settings::class_list() owns the rule, because the
	 * function is core's, this plugin's floor is WordPress 6.0, and an
	 * undefined function inside Settings::all() is a white page on every page
	 * carrying a map. This stub is the other half of a cross-check in
	 * tests/test-settings-sanitise.php.
	 *
	 * A port rather than an approximation. wp-includes/formatting.php line 2500
	 * of WordPress 6.9.1 does two substitutions in this order — percent-encoded
	 * pairs first, then everything outside `[A-Za-z0-9_-]` — and the order is
	 * the part worth being faithful about: reversed, `%3Cscript%3E` leaves
	 * `3Cscript3E` behind instead of `script`.
	 *
	 * Two things core does that this does not, both deliberate. The fallback
	 * argument is implemented, because it is part of the answer. The
	 * `sanitize_html_class` filter is not applied, because there is no filter
	 * in this plugin's own rule either and the cross-check compares rules
	 * rather than hook behaviour — a stub that ran filters would make the case
	 * green or red depending on what a test registered elsewhere.
	 *
	 * @param mixed  $classname Class name.
	 * @param string $fallback  Value when nothing survives.
	 * @return string
	 */
	function sanitize_html_class( $classname, $fallback = '' ) {
		$sanitized = (string) preg_replace( '|%[a-fA-F0-9][a-fA-F0-9]|', '', (string) $classname );
		$sanitized = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $sanitized );

		if ( '' === $sanitized && $fallback ) {
			return sanitize_html_class( $fallback );
		}

		return $sanitized;
	}
}

if ( ! function_exists( 'sanitize_hex_color' ) ) {
	/**
	 * Validates a hex colour, answering with core's three return shapes.
	 *
	 * Nothing in this plugin calls it any more, and that is the point of it
	 * being here. Settings::colour() owns the rule outright, because the
	 * function is core's and this plugin's floor is WordPress 6.0 — which there
	 * is no copy of in this tree, and no way to check without a network request
	 * this repository forbids. An undefined function inside Settings::all()
	 * would be a fatal on every front-end page carrying a locator.
	 *
	 * So this stub exists to be the *other half of a cross-check*: a case in
	 * tests/test-settings-sanitise.php runs thirteen values through both and
	 * asserts they agree, which is what stops the copy drifting from the
	 * original on every version that does have one. That only works if the stub
	 * is a faithful port, which is what the rest of this docblock is about.
	 *
	 * A port rather than an approximation, because the return type is the trap:
	 * wp-includes/formatting.php line 6249 of WordPress 6.9.1 returns '' for an
	 * empty string, the colour for a valid one, and **nothing at all** — null —
	 * for anything invalid. Code written against a stub that answered '' for
	 * invalid would treat "red" as a colour of no length and print
	 * `background:` with nothing after it.
	 *
	 * The pattern is core's own, `|^#([A-Fa-f0-9]{3}){1,2}$|`, so three and six
	 * digits are accepted, four and eight are not, and the leading hash is
	 * required.
	 *
	 * @param mixed $color Colour.
	 * @return string|null '' , the colour, or null.
	 */
	function sanitize_hex_color( $color ) {
		$color = (string) $color;

		if ( '' === $color ) {
			return '';
		}

		if ( preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', $color ) ) {
			return $color;
		}

		return null;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Sanitizes a url for storage.
	 *
	 * A short version, not a port of esc_url(), but the protocol allowlist is
	 * modelled exactly rather than approximated — and that distinction is the
	 * whole reason this docblock is long.
	 *
	 * An earlier version of this stub allowed http and https and nothing else,
	 * and said so as though that were what WordPress does. It is not.
	 * esc_url_raw( $url ) is sanitize_url( $url, null ), which is
	 * esc_url( $url, null, 'db' ), and a null protocol list means
	 * wp_allowed_protocols() — twenty-two of them, including ftp, telnet, svn,
	 * mailto and webcal. Verified in wp-includes/formatting.php and
	 * wp-includes/functions.php of WordPress 6.9.1.
	 *
	 * That gap was a stub lying in the passing direction. The Geocoder's
	 * "falls back to the default endpoint when the configured one is not http"
	 * case passed against it while the production code called esc_url_raw()
	 * with no protocol list at all — so a site configuring an ftp:// endpoint
	 * would have sailed through sanitisation and spent forever on a transport
	 * error. The fixture used javascript:, which core strips too, so the case
	 * looked sound. Code that wants http and https has to ask for them, exactly
	 * as it must in production, and this stub is now strict about that.
	 *
	 * The character stripping IS modelled, and it was added for Task 21 after
	 * being listed here for three stages as something that was not. It is
	 * core's own expression, verbatim:
	 *
	 *     preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', $url )
	 *
	 * wp-includes/formatting.php, esc_url(), of WordPress 6.9.1. It is here
	 * because **`{` and `}` are not in that class**, so core's sanitiser turns
	 * `https://tile.example/{z}/{x}/{y}.png` into
	 * `https://tile.example/z/x/y.png` — a tile url with no placeholders left
	 * in it, which is exactly the value Task 21's settings screen exists to
	 * refuse. A stub without this would have let `Settings::tile_url()` be
	 * written on top of `esc_url_raw()`, pass every case here, and reject every
	 * correctly-typed tile url on a real site.
	 *
	 * What is still not modelled: the space-to-%20 replacement (this removes
	 * whitespace instead), the %0a/%0d stripping, the ';//' fixup, the bracket
	 * encoding, the entity handling, the 'display' context, the clean_url
	 * filter, and core 6.9's rule that a schemeless url gains https:// when
	 * 'https' leads the protocol list — here it always gains http://.
	 * Schemeless urls otherwise keep their own shape, as core does: "//host",
	 * "/path", "#frag" and "?query" are left alone.
	 *
	 * @param mixed      $url       Url.
	 * @param array|null $protocols Acceptable protocols; null means core's full list.
	 * @return string
	 */
	function esc_url_raw( $url, $protocols = null ) {
		$url = preg_replace( '/[\r\n\t ]+/', '', (string) $url );
		$url = preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', (string) $url );

		if ( '' === $url ) {
			return '';
		}

		if ( in_array( $url[0], array( '/', '#', '?' ), true ) ) {
			return $url;
		}

		// wp_allowed_protocols(), verbatim, as of WordPress 6.9.1.
		$allowed = is_array( $protocols ) ? $protocols : array(
			'http',
			'https',
			'ftp',
			'ftps',
			'mailto',
			'news',
			'irc',
			'irc6',
			'ircs',
			'gopher',
			'nntp',
			'feed',
			'telnet',
			'mms',
			'rtsp',
			'sms',
			'svn',
			'tel',
			'fax',
			'xmpp',
			'webcal',
			'urn',
		);

		if ( preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $url, $match ) ) {
			return in_array( strtolower( $match[1] ), $allowed, true ) ? $url : '';
		}

		return 'http://' . $url;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Escapes a value for an html attribute.
	 *
	 * @param mixed $str Value.
	 * @return string
	 */
	function esc_attr( $str ) {
		return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8', false );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escapes a value for html output.
	 *
	 * @param mixed $str Value.
	 * @return string
	 */
	function esc_html( $str ) {
		return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8', false );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Casts a value to a non-negative integer.
	 *
	 * @param mixed $n Value.
	 * @return int
	 */
	function absint( $n ) {
		return abs( (int) $n );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * Validates an email address, returning the address or false.
	 *
	 * string|false, not bool, and that is core's signature rather than a
	 * flourish: wp-includes/formatting.php line 3633 of WordPress 6.9.1 ends
	 * `return apply_filters( 'is_email', $email, $email, null )`. Code written
	 * against a bool stub can compare with === true and pass here while being
	 * wrong on every real site.
	 *
	 * An earlier version of this stub was filter_var( ..., FILTER_VALIDATE_EMAIL ),
	 * which is a different validator with different answers — it accepts
	 * "a@b" and "user@[127.0.0.1]", both of which core refuses, and it refuses
	 * nothing core accepts that this plugin's fields can produce. The rules
	 * below are core's own, in core's order.
	 *
	 * Not ported: the is_email filter at each exit, and the deprecated second
	 * argument.
	 *
	 * @param mixed $email Address.
	 * @return string|false The address when it passes, false otherwise.
	 */
	function is_email( $email ) {
		$email = (string) $email;

		// Minimum length, an "@" after the first character, and a split.
		if ( strlen( $email ) < 6 || false === strpos( $email, '@', 1 ) ) {
			return false;
		}

		list( $local, $domain ) = explode( '@', $email, 2 );

		if ( '' === $local || ! preg_match( '/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~\.-]+$/', $local ) ) {
			return false;
		}

		if ( '' === $domain || false !== strpos( $domain, '..' ) ) {
			return false;
		}

		if ( trim( $domain, " \t\n\r\0\x0B." ) !== $domain ) {
			return false;
		}

		$subs = explode( '.', $domain );

		// Core assumes at least two: "kontakt@sklep" is not an email address to
		// WordPress, however willing a mail server might be.
		if ( 2 > count( $subs ) ) {
			return false;
		}

		foreach ( $subs as $sub ) {
			if ( trim( $sub, " \t\n\r\0\x0B-" ) !== $sub || ! preg_match( '/^[a-z0-9-]+$/i', $sub ) ) {
				return false;
			}
		}

		return $email;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Translates, if the case asked for a translation.
	 *
	 * Untranslated by default, which is what almost every case wants. A case
	 * that needs the other state — a site with a language pack installed, so
	 * that what comes back is a string out of a file the site owner did not
	 * write — sets `$GLOBALS['slosm_stub']['translations'][ $text ]`.
	 *
	 * The domain is ignored on purpose. Modelling it would mean modelling
	 * which .mo files are loaded, and what the cases that use this need is
	 * "this string comes back changed", not a gettext implementation.
	 *
	 * @param string $text   Text.
	 * @param string $domain Unused; the stub has one namespace.
	 * @return string
	 */
	function __( $text, $domain = null ) {
		$translations = $GLOBALS['slosm_stub']['translations'] ?? array();

		return isset( $translations[ $text ] ) ? (string) $translations[ $text ] : $text;
	}
}

if ( ! function_exists( '_x' ) ) {
	/**
	 * Translates a string that carries a disambiguating context.
	 *
	 * The context is not decoration here: gettext keys an entry by
	 * `msgctxt . "\4" . msgid`, so `_x( 'Search', 'submit button…' )` and
	 * `__( 'Search' )` are two separate entries in the .po and a translation
	 * of one must not answer for the other. That is the whole reason
	 * Shortcode::filters() uses this function for the submit button, so the
	 * stub models the key rather than ignoring the context — a stub that fell
	 * back to the plain msgid would agree with the code under test and prove
	 * nothing, which is the shape this project's tests/js/harness.js is under
	 * mutation for.
	 *
	 * @param string $text    Text.
	 * @param string $context What tells this msgid apart from the same words elsewhere.
	 * @param string $domain  Unused; the stub has one namespace.
	 * @return string
	 */
	function _x( $text, $context, $domain = null ) {
		$translations = $GLOBALS['slosm_stub']['translations'] ?? array();
		$key          = $context . "\4" . $text;

		return isset( $translations[ $key ] ) ? (string) $translations[ $key ] : $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * Picks the singular or plural form.
	 *
	 * @param string $single Singular form.
	 * @param string $plural Plural form.
	 * @param int    $number Count.
	 * @param string $domain Unused; the stub never translates.
	 * @return string
	 */
	function _n( $single, $plural, $number, $domain = null ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Translates and then escapes, which is core's order.
	 *
	 * `wp-includes/l10n.php` line 340 of WordPress 6.9.1 declares this as
	 * `esc_html( translate( $text, $domain ) )`, and the order is the whole
	 * point of the function: the thing being escaped is the *translation*,
	 * not the source string.
	 *
	 * This used to return `$text` unchanged, which made it identical to
	 * `__()` in every case the suite runs — so no case could tell code that
	 * escapes a translated string from code that does not. It escapes now,
	 * and no case changed: esc_html() is the identity on every English
	 * string this plugin ships, which is exactly why the difference has to be
	 * made visible through `translations` rather than hoped about.
	 *
	 * @param string $text   Text.
	 * @param string $domain Passed to __(); the stub has one namespace.
	 * @return string
	 */
	function esc_html__( $text, $domain = null ) {
		return esc_html( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Encodes a value as json.
	 *
	 * @param mixed $data  Value.
	 * @param int   $flags Json flags.
	 * @return string|false
	 */
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * Merges arguments over a set of defaults.
	 *
	 * @param mixed $args     Arguments.
	 * @param array $defaults Defaults.
	 * @return array
	 */
	function wp_parse_args( $args, $defaults ) {
		return array_merge( $defaults, (array) $args );
	}
}

if ( ! function_exists( 'shortcode_atts' ) ) {
	/**
	 * Filters shortcode attributes against a set of defaults.
	 *
	 * @param array  $pairs     Supported attributes and their defaults.
	 * @param mixed  $atts      Attributes supplied by the user.
	 * @param string $shortcode Unused; the stub applies no shortcode_atts_* filter.
	 * @return array
	 */
	function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
		$atts = (array) $atts;
		$out  = array();

		foreach ( $pairs as $name => $default ) {
			$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
		}

		// Core runs this filter whenever the third argument is truthy, and it
		// returns whatever the filter returned — an array short a key, or
		// something that is not an array at all. Modelled rather than skipped
		// because that is the one way this function can hand a caller something
		// it did not ask for, and a caller reading the result with direct array
		// access raises "Undefined array key" on a front-end page.
		if ( $shortcode ) {
			$out = apply_filters( "shortcode_atts_{$shortcode}", $out, $pairs, $atts, $shortcode );
		}

		return $out;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Reads stubbed post meta.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param bool   $single  Whether to return a scalar.
	 * @return mixed Empty string or empty array when there is no value.
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		if ( ! isset( $GLOBALS['slosm_stub']['post_meta'][ $post_id ][ $key ] ) ) {
			return $single ? '' : array();
		}

		$value = $GLOBALS['slosm_stub']['post_meta'][ $post_id ][ $key ];

		return $single ? $value : array( $value );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * Writes stubbed post meta, unslashing on the way in as WordPress does.
	 *
	 * The unslash is not decoration and it is not this stub being clever. Core's
	 * update_metadata() calls wp_unslash( $meta_value ) at wp-includes/meta.php
	 * line 222 of WordPress 6.9.1, so update_post_meta() takes a *slashed*
	 * value and stores the unslashed one. Every save handler on every site is
	 * written against that, whether or not its author knows it.
	 *
	 * A stub that stored the value verbatim would make the correct pipeline —
	 * wp_unslash() the superglobal, sanitise the real text, wp_slash() it back
	 * for this call — look like it was adding stray backslashes, and would make
	 * the common wrong one, which drops every literal backslash an editor
	 * types, look right. Task 17's "stores an apostrophe and a backslash exactly
	 * as typed" case is the one that depends on this line.
	 *
	 * Not modelled: the meta cache, revisions, protected keys, sanitize_meta()
	 * and the update_post_metadata short-circuit filter.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value, slashed the way a caller must pass it.
	 * @return bool
	 */
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['slosm_stub']['post_meta'][ $post_id ][ $key ] = wp_unslash( $value );
		return true;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * Records the query and hands back whatever rows the test put in place.
	 *
	 * A recorder, not a query. Nothing here reads post_type, post_status or
	 * numberposts, so a test cannot prove anything by looking at which rows came
	 * back — the rows are simply the ones it staged. What the plugin controls is
	 * the argument array, and that is what a test asserts on, the same way the
	 * register_post_type() stub works.
	 *
	 * The default is an empty list rather than an exception, because "no
	 * locations yet" is an ordinary state of a real site and code under test has
	 * to handle it.
	 *
	 * @param array $args Query arguments, recorded verbatim.
	 * @return array
	 */
	function get_posts( $args = array() ) {
		$GLOBALS['slosm_stub']['post_queries'][] = $args;

		/*
		 * Task 25's branch, and it is deliberately the only one that reads the
		 * arguments at all. Everything else in this plugin loads locations to
		 * show them and gets the staged rows back whatever it asked for, which
		 * is what the docblock above describes. The uninstaller instead asks
		 * for ids, in pages, and deletes what comes back — a loop whose exit
		 * depends on the answer shrinking. A stub that handed back the same
		 * rows for ever would make that loop run for ever, so `fields` and
		 * `numberposts` are honoured here and nowhere else.
		 *
		 * The post type is honoured too, because "delete every location" and
		 * "delete every post" are the two answers this branch has to be able
		 * to tell apart.
		 *
		 * One deliberate difference from core, in the safe direction: a
		 * `'post_type' => 'any'` here matches every staged row, where core
		 * expands it to the registered types that do not carry
		 * exclude_from_search (class-wp-query.php line 5006) — which, for a
		 * post type registered private, is none of them. So this stub judges
		 * a mutant that reaches for 'any' more harshly than a real site would,
		 * never more kindly, and it cannot hide a deletion that a real site
		 * would perform.
		 */
		if ( ! isset( $args['fields'] ) || 'ids' !== $args['fields'] ) {
			return $GLOBALS['slosm_stub']['posts'];
		}

		$rows = $GLOBALS['slosm_stub']['posts'];

		if ( isset( $args['post_type'] ) ) {
			$types = (array) $args['post_type'];
			$rows  = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $types ): bool {
						return in_array( 'any', $types, true )
							|| in_array( (string) ( $row['post_type'] ?? '' ), $types, true );
					}
				)
			);
		}

		if ( isset( $args['post_status'] ) ) {
			$statuses = (array) $args['post_status'];

			/*
			 * 'any' is not "every status", and the difference is the whole
			 * point of one case here. WP_Query expands it to every registered
			 * status *except* the ones carrying exclude_from_search —
			 * class-wp-query.php line 2656 of WordPress 6.9.1 — which are
			 * `trash` and `auto-draft`. A stub that read 'any' as everything
			 * would agree with an uninstaller that used it, and a location in
			 * the trash would survive a "delete every location" that this
			 * suite called correct.
			 *
			 * That mistake was in this stub, and it was a surviving mutant
			 * that found it rather than a review.
			 */
			if ( in_array( 'any', $statuses, true ) ) {
				$statuses = array_values(
					array_diff( array_keys( get_post_stati() ), array( 'trash', 'auto-draft' ) )
				);
			}

			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $statuses ): bool {
						return in_array( (string) ( $row['post_status'] ?? 'publish' ), $statuses, true );
					}
				)
			);
		}

		$ids = array_map(
			static function ( $row ): int {
				return (int) ( $row['ID'] ?? 0 );
			},
			$rows
		);

		$number = isset( $args['numberposts'] ) ? (int) $args['numberposts'] : 5;

		return 0 < $number ? array_slice( $ids, 0, $number ) : $ids;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Returns the one row a test staged under an id, or null.
	 *
	 * Rows are staged whole, so post_type and post_status are the test's to set
	 * and the code under test has to read them itself. That is the point: this
	 * stub answers with whatever is under the id, exactly as a database does,
	 * and a caller that never checks the type will happily hand back a page.
	 *
	 * Null for an unknown id, which is what WordPress returns and is what
	 * separates "no such location" from "a location with no fields".
	 *
	 * @param int|object|null $post Post id, or something with an ID.
	 * @return object|null
	 */
	function get_post( $post = null ) {
		$id = is_object( $post ) ? ( $post->ID ?? 0 ) : (int) $post;

		return $GLOBALS['slosm_stub']['posts_by_id'][ $id ] ?? null;
	}
}

if ( ! function_exists( 'get_the_terms' ) ) {
	/**
	 * Returns the terms a test staged for an object, in WordPress's three shapes.
	 *
	 * The shapes are modelled rather than recorded, because all three are how
	 * this function actually fails and each one breaks different code:
	 *
	 * - Term objects, never names. get_the_terms() has no 'fields' argument, so
	 *   a caller that forgets to pluck the names hands Store a list of objects,
	 *   which Store drops — a silently empty category list. A stub returning
	 *   strings would make that bug green.
	 * - false when there are none, which is what WordPress returns rather than
	 *   an empty array, and also what it returns for a post that does not exist.
	 *   A guard written as is_array() survives it; one written as is_wp_error()
	 *   alone does not.
	 * - A staged WP_Error, which is what an unregistered taxonomy gives — a call
	 *   before init.
	 *
	 * The taxonomy is recorded and otherwise ignored: terms are keyed by object
	 * id alone, so a test proving the right taxonomy was asked for has to read
	 * the recorded request.
	 *
	 * @param int|object $post     Object id, or something with an ID.
	 * @param string     $taxonomy Taxonomy; recorded, not honoured.
	 * @return array|false|WP_Error
	 */
	function get_the_terms( $post, $taxonomy ) {
		$object_id = is_object( $post ) ? ( $post->ID ?? 0 ) : (int) $post;

		$GLOBALS['slosm_stub']['term_requests'][] = array(
			'object_id' => $object_id,
			'taxonomy'  => $taxonomy,
		);

		$staged = $GLOBALS['slosm_stub']['object_terms'][ $object_id ] ?? array();

		if ( $staged instanceof WP_Error ) {
			return $staged;
		}

		if ( empty( $staged ) ) {
			return false;
		}

		$terms = array();

		foreach ( (array) $staged as $name ) {
			// Stands in for WP_Term, which has no __toString(): casting one
			// yields '', which is the blank category chip Store refuses to make.
			$terms[] = (object) array(
				'name'    => $name,
				'slug'    => $name,
				'term_id' => 0,
			);
		}

		return $terms;
	}
}

if ( ! function_exists( 'wp_list_pluck' ) ) {
	/**
	 * Pulls one field out of every element of a list.
	 *
	 * The list is iterated exactly as handed over, with no (array) cast, because
	 * WP_List_Util does not cast either: core assigns the input straight to a
	 * property and foreaches it. The difference matters. get_the_terms() answers
	 * false for a location with no categories, and foreach over false is a PHP 8
	 * warning — on a front-end page, for every uncategorised location — which a
	 * caller has to guard against. A cast here would absorb that and make the
	 * missing guard untestable.
	 *
	 * @param array      $list      Objects or arrays.
	 * @param string|int $field     Field to pull.
	 * @param string|int $index_key Field to key the result by, or null to keep the original keys.
	 * @return array
	 */
	function wp_list_pluck( $list, $field, $index_key = null ) {
		$plucked = array();

		foreach ( $list as $key => $item ) {
			$value = is_object( $item ) ? ( $item->$field ?? null ) : ( $item[ $field ] ?? null );

			if ( null === $index_key ) {
				$plucked[ $key ] = $value;
				continue;
			}

			$index = is_object( $item ) ? ( $item->$index_key ?? null ) : ( $item[ $index_key ] ?? null );

			$plucked[ $index ] = $value;
		}

		return $plucked;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Returns the site's front-page url.
	 *
	 * Core reads this from the 'home' option — get_home_url() is a get_option()
	 * call with a scheme fixup around it — so this stub reads the same option,
	 * and a test that cares about the value sets it. The default is a url rather
	 * than an empty string because the one caller that matters, the Geocoder's
	 * User-Agent, is only honest on a site that has one.
	 *
	 * Three things core does that this does not: it forces a scheme, it is
	 * multisite-aware through get_blog_details(), and it runs the 'home_url'
	 * filter. Nothing in this plugin depends on any of them, and a stub that
	 * pretended to would be inventing behaviour rather than standing in for it.
	 *
	 * @param string      $path   Path appended to the url.
	 * @param string|null $scheme Ignored; the stub forces no scheme.
	 * @return string
	 */
	function home_url( $path = '', $scheme = null ) {
		$url = rtrim( (string) get_option( 'home', 'https://example.test' ), '/' );

		return '' === (string) $path ? $url : $url . '/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'determine_locale' ) ) {
	/**
	 * Returns the locale the request is being served in.
	 *
	 * The site locale, from the option WordPress keeps it in. Nothing here knows
	 * about user locales or the admin, which is the part real sites vary; a test
	 * that cares sets the option.
	 *
	 * @return string
	 */
	function determine_locale() {
		return (string) get_option( 'locale', 'en_US' );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Returns the current time off the stub clock.
	 *
	 * Site-local by default, like WordPress: the gmt_offset option is applied
	 * unless $gmt asks for UTC. A test that cares sets that option.
	 *
	 * @param string   $type 'mysql' for a 'Y-m-d H:i:s' string, anything else for a timestamp.
	 * @param int|bool $gmt  Whether to use UTC rather than site-local time.
	 * @return int|string
	 */
	function current_time( $type = 'timestamp', $gmt = 0 ) {
		$now = (int) slosm_stub_time();

		if ( ! $gmt ) {
			$now += (int) ( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
		}

		return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s', $now ) : $now;
	}
}

if ( ! function_exists( '__return_true' ) ) {
	/**
	 * Returns true. Core's own helper, and the permission callback of every
	 * route this plugin registers.
	 *
	 * @return bool
	 */
	function __return_true() {
		return true;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Records a route registration. Nothing here routes anything.
	 *
	 * The controller's callbacks are called directly by the tests, so this stub
	 * exists to let a test read back what was declared — the namespace, the
	 * route pattern, the permission callback and the args schema — not to serve
	 * a request.
	 *
	 * Deliberately not modelled, and each omission is something a test must not
	 * lean on:
	 *
	 * - The namespace and route are not validated. Core warns via
	 *   _doing_it_wrong() for an empty namespace or a missing
	 *   permission_callback; this stub accepts both silently, so a test that
	 *   wants those guaranteed has to assert on the recorded values itself.
	 * - 'methods' is not normalised. Core turns 'GET' into
	 *   array( 'GET' => true ) inside the handler; this keeps whatever was
	 *   passed.
	 * - $override is recorded and otherwise ignored; nothing here can collide.
	 *
	 * @param string $namespace Route namespace.
	 * @param string $route     Route pattern.
	 * @param array  $args      Endpoint definitions, or one definition.
	 * @param bool   $override  Whether to replace an existing route.
	 * @return bool Always true.
	 */
	function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
		$GLOBALS['slosm_stub']['rest_routes'][] = array(
			'namespace' => $namespace,
			'route'     => $route,
			'args'      => $args,
			'override'  => $override,
		);

		return true;
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal stand-in for the REST response object.
	 *
	 * Data, status and headers, which is all this plugin puts into one. It does
	 * not extend WP_HTTP_Response, carries no links and no matched route, and
	 * serialises nothing — WP_REST_Server is what would use any of that, and
	 * there is no server here.
	 *
	 * set_status() uses (int) where core uses absint(), which differ only for a
	 * negative code. Nothing in this plugin can produce one; a test that wanted
	 * to pin core's clamping would be pinning this stub instead.
	 */
	class WP_REST_Response {

		/**
		 * Response body.
		 *
		 * @var mixed
		 */
		protected $data;

		/**
		 * HTTP status code.
		 *
		 * @var int
		 */
		protected $status = 200;

		/**
		 * Headers, keyed by name.
		 *
		 * @var array<string, string>
		 */
		protected $headers = array();

		/**
		 * Constructor.
		 *
		 * @param mixed $data    Response body.
		 * @param int   $status  HTTP status code.
		 * @param array $headers Headers, keyed by name.
		 */
		public function __construct( $data = null, $status = 200, $headers = array() ) {
			$this->data = $data;

			$this->set_status( $status );
			$this->set_headers( $headers );
		}

		/**
		 * Returns the response body.
		 *
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		/**
		 * Sets the response body.
		 *
		 * @param mixed $data Response body.
		 * @return void
		 */
		public function set_data( $data ) {
			$this->data = $data;
		}

		/**
		 * Returns the status code.
		 *
		 * @return int
		 */
		public function get_status() {
			return $this->status;
		}

		/**
		 * Sets the status code.
		 *
		 * @param int $code Status code.
		 * @return void
		 */
		public function set_status( $code ) {
			$this->status = (int) $code;
		}

		/**
		 * Returns every header.
		 *
		 * @return array<string, string>
		 */
		public function get_headers() {
			return $this->headers;
		}

		/**
		 * Replaces every header.
		 *
		 * @param array $headers Headers, keyed by name.
		 * @return void
		 */
		public function set_headers( $headers ) {
			$this->headers = (array) $headers;
		}

		/**
		 * Sets one header.
		 *
		 * @param string $key     Header name.
		 * @param string $value   Header value.
		 * @param bool   $replace Whether to replace an existing value.
		 * @return void
		 */
		public function header( $key, $value, $replace = true ) {
			if ( $replace || ! isset( $this->headers[ $key ] ) ) {
				$this->headers[ $key ] = $value;

				return;
			}

			$this->headers[ $key ] .= ',' . $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal stand-in for the REST request object.
	 *
	 * One bag of parameters and one bag of defaults, which is the part of core's
	 * behaviour a route callback can observe: get_param() prefers a real
	 * parameter and falls back to the default WP_REST_Server::match_request_to_handler()
	 * copied off the args schema. isset(), not array_key_exists(), exactly as
	 * core does — a parameter explicitly set to null falls through to the
	 * default there too.
	 *
	 * Deliberately not modelled:
	 *
	 * - Parameter precedence between JSON, POST, query, URL and defaults. Core
	 *   keeps five separate bags and returns the first that has the key; this
	 *   keeps one. Nothing in this plugin cares which bag a value came from.
	 * - Validation and sanitisation. In production those run against the args
	 *   schema *before* the callback — has_valid_params() then sanitize_params()
	 *   in WP_REST_Server::respond_to_request(), verified in WordPress 6.9.1 —
	 *   so a callback never sees a value the schema rejected. This stub runs
	 *   neither, which makes it strictly more permissive than WordPress: a test
	 *   can hand a callback a radius of one million, and that proves nothing
	 *   about what a visitor can send. Clamping is asserted on the declared
	 *   schema, which is what WordPress enforces.
	 * - Headers, body, method, route and attributes.
	 */
	class WP_REST_Request {

		/**
		 * Parameters, keyed by name.
		 *
		 * @var array
		 */
		protected $params = array();

		/**
		 * Defaults taken off the args schema, keyed by name.
		 *
		 * @var array
		 */
		protected $defaults = array();

		/**
		 * Constructor.
		 *
		 * @param array $params Parameters, keyed by name.
		 */
		public function __construct( array $params = array() ) {
			$this->params = $params;
		}

		/**
		 * Returns one parameter, or null.
		 *
		 * @param string $key Parameter name.
		 * @return mixed
		 */
		public function get_param( $key ) {
			if ( isset( $this->params[ $key ] ) ) {
				return $this->params[ $key ];
			}

			if ( isset( $this->defaults[ $key ] ) ) {
				return $this->defaults[ $key ];
			}

			return null;
		}

		/**
		 * Sets one parameter.
		 *
		 * @param string $key   Parameter name.
		 * @param mixed  $value Value.
		 * @return void
		 */
		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}

		/**
		 * Whether a parameter was supplied at all, null included.
		 *
		 * @param string $key Parameter name.
		 * @return bool
		 */
		public function has_param( $key ) {
			return array_key_exists( $key, $this->params ) || array_key_exists( $key, $this->defaults );
		}

		/**
		 * Every parameter, defaults underneath.
		 *
		 * @return array
		 */
		public function get_params() {
			$params = $this->defaults;

			foreach ( $this->params as $key => $value ) {
				$params[ $key ] = $value;
			}

			return $params;
		}

		/**
		 * Sets the defaults, the way the server does after matching a route.
		 *
		 * @param array $params Defaults, keyed by name.
		 * @return void
		 */
		public function set_default_params( $params ) {
			$this->defaults = (array) $params;
		}
	}
}

/*
 * ---------------------------------------------------------------------------
 * Task 11 additions: four stubs the shortcode needs and nothing before it did.
 *
 * Each one stands in for a WordPress function this suite had never called, and
 * each carries a note saying where it stops resembling the real thing. That
 * note is the point: a stub more permissive than WordPress lets a test pass
 * against code a real site would break on, and a stub stricter than WordPress
 * makes a correct implementation look wrong.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'OBJECT' ) ) {
	/**
	 * Core's default $output for the term and post getters.
	 *
	 * Defined in wp-includes/class-wpdb.php on a real site, long before any
	 * plugin file loads, so get_term_by()'s signature can name it.
	 */
	define( 'OBJECT', 'OBJECT' );
}

if ( ! function_exists( 'add_shortcode' ) ) {
	/**
	 * Records a shortcode registration.
	 *
	 * Appended per tag rather than assigned, and that is a deliberate departure
	 * from core: WordPress does `$shortcode_tags[ $tag ] = $callback`, so a
	 * second registration of the same tag silently replaces the first and is
	 * invisible afterwards. Appending makes a double registration countable,
	 * which is what a test proving "registered exactly once" needs. A test
	 * reading two entries here is therefore reading this plugin's behaviour,
	 * never WordPress's.
	 *
	 * Not modelled: core's two _doing_it_wrong() paths, for an empty tag and for
	 * a tag containing any of `& / < > [ ] =` or whitespace. This stub accepts
	 * both silently, so a test that wants the tag to be a legal one has to
	 * assert on the recorded tag itself.
	 *
	 * @param string   $tag      Shortcode tag.
	 * @param callable $callback Render callback.
	 * @return bool
	 */
	function add_shortcode( $tag, $callback ) {
		$GLOBALS['slosm_stub']['shortcodes'][ $tag ][] = array( 'callback' => $callback );

		return true;
	}
}

if ( ! function_exists( 'wp_count_posts' ) ) {
	/**
	 * Returns the staged status counts for a post type.
	 *
	 * Three properties of the real function are modelled, because code reading
	 * this has to survive all three:
	 *
	 * - An object, never an array. Core builds `(object) $counts`.
	 * - An *empty* stdClass when the post type is not registered, with no
	 *   `publish` property at all. Reading `->publish` off that is an
	 *   "Undefined property" warning in PHP 8, which framework.php now counts as
	 *   a failure — so a caller that does not check first fails loudly here
	 *   rather than printing a warning on a real front-end page.
	 * - Counts as whatever the test stages. On a real site they arrive from
	 *   `$wpdb->get_results( ..., ARRAY_A )`, so every status that has rows
	 *   comes back as a numeric *string* and only the statuses filled in by
	 *   `array_fill_keys( get_post_stati(), 0 )` are integers. A caller
	 *   comparing `500 < $counts->publish` against the string '501' gets the
	 *   right answer by luck; one comparing with === does not. The suite stages
	 *   strings on purpose in at least one case.
	 *
	 * Not modelled: the 'counts' object cache, the `readable` permission branch,
	 * get_post_stati() zero-filling, and the `wp_count_posts` filter.
	 *
	 * @param string $type Post type key.
	 * @param string $perm Recorded nowhere and ignored; core's 'readable' branch is not modelled.
	 * @return object
	 */
	function wp_count_posts( $type = 'post', $perm = '' ) {
		$staged = $GLOBALS['slosm_stub']['post_counts'][ $type ] ?? null;

		if ( null === $staged ) {
			return new stdClass();
		}

		return (object) $staged;
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	/**
	 * Finds one staged term by a field.
	 *
	 * Terms are staged per taxonomy in $GLOBALS['slosm_stub']['terms'], as plain
	 * arrays with at least term_id, name and slug. Every lookup is recorded in
	 * 'term_lookups', so a test can prove which field was tried and in what
	 * order.
	 *
	 * What it copies from core: 'id', 'ID' and 'term_id' all mean the numeric
	 * id; an empty string for 'slug' or 'name' is false without a lookup; an
	 * unknown field is false; a taxonomy with nothing staged is false, which is
	 * also how core answers when the taxonomy is not registered.
	 *
	 * What it does not: it returns a plain object, not a WP_Term, so code that
	 * type-hints WP_Term would break on a real site and pass here — read
	 * properties, hint nothing. It ignores $output and $filter entirely, so
	 * ARRAY_A and the 'raw' filter chain are untested. It matches 'name' and
	 * 'slug' byte for byte, while core's WP_Term_Query normalises a name
	 * through sanitize_term_field() and a slug through sanitize_title(), so a
	 * real site is more forgiving about case and accents than this is.
	 *
	 * @param string $field    'id', 'ID', 'term_id', 'slug' or 'name'.
	 * @param mixed  $value    Value to match.
	 * @param string $taxonomy Taxonomy key.
	 * @param string $output   Ignored.
	 * @param string $filter   Ignored.
	 * @return object|false
	 */
	function get_term_by( $field, $value, $taxonomy = '', $output = OBJECT, $filter = 'raw' ) {
		$GLOBALS['slosm_stub']['term_lookups'][] = array(
			'field'    => $field,
			'value'    => $value,
			'taxonomy' => $taxonomy,
		);

		$staged = $GLOBALS['slosm_stub']['terms'][ $taxonomy ] ?? array();

		if ( array() === $staged ) {
			return false;
		}

		if ( in_array( $field, array( 'id', 'ID', 'term_id' ), true ) ) {
			foreach ( $staged as $term ) {
				if ( (int) $term['term_id'] === (int) $value ) {
					return (object) $term;
				}
			}

			return false;
		}

		if ( ! in_array( $field, array( 'slug', 'name' ), true ) ) {
			return false;
		}

		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return false;
		}

		foreach ( $staged as $term ) {
			if ( (string) ( $term[ $field ] ?? '' ) === (string) $value ) {
				return (object) $term;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * The url a REST route lives at.
	 *
	 * Both of core's branches, chosen the way get_rest_url() chooses them: on
	 * the truthiness of the permalink_structure option. A test that wants the
	 * pretty form sets that option, exactly as a site does by visiting Settings
	 * > Permalinks, and the suite's default — an unset option — is therefore the
	 * *plain* form. That is deliberate. An earlier version of this stub always
	 * returned the pretty form, which made the branch that breaks url building
	 * unreachable by any test in the suite.
	 *
	 * The plain branch is written the way core arrives at it rather than the way
	 * it looks. get_rest_url() appends 'index.php' and then calls
	 * add_query_arg( 'rest_route', $path, $url ); add_query_arg() urlencode_deep()s
	 * only the query string it parsed out of the url, and assigns the new
	 * argument afterwards, and build_query() calls _http_build_query() with
	 * $urlencode false — so the route is placed verbatim, percent signs and all.
	 * Plain concatenation here is faithful to that, and it is what makes a %d in
	 * a route survive into the url as a malformed percent escape.
	 *
	 * Absent: the 'rest_url' filter, rest_get_url_prefix(), index permalinks,
	 * the https fixups and multisite.
	 *
	 * @param string $path   Path within the REST root.
	 * @param string $scheme Ignored.
	 * @return string
	 */
	function rest_url( $path = '', $scheme = 'rest' ) {
		$path = '/' . ltrim( (string) $path, '/' );

		if ( get_option( 'permalink_structure', '' ) ) {
			return home_url( '/wp-json' ) . $path;
		}

		return home_url( '/index.php' ) . '?rest_route=' . $path;
	}
}

/*
 * ---------------------------------------------------------------------------
 * Task 12 additions: the script and style registry.
 *
 * These are recorders, but the shape of what they record is copied from
 * WordPress 6.9.1 rather than invented, because three of core's rules are the
 * whole subject of tests/test-assets.php and a stub that got any of them wrong
 * would make a broken implementation look right:
 *
 * - wp_register_script()'s fifth argument is overloaded. A non-array is cast to
 *   array( 'in_footer' => (bool) $args ), verified in
 *   wp-includes/functions.wp-scripts.php lines 182-186 of 6.9.1. Both shapes
 *   therefore have to land in the same place here, and the raw argument is
 *   recorded separately so a test can still see which one was passed.
 * - A second registration of a handle does not overwrite the first.
 *   WP_Dependencies::add() returns false and leaves the original in place.
 * - wp_localize_script() called twice for one handle *concatenates*; it does not
 *   replace. WP_Scripts::localize() prepends whatever get_data( $handle, 'data' )
 *   already held. That is why the calls are recorded individually: two calls are
 *   two `var x = {...}` statements in the page, which is the bug a "localizes
 *   once" test is about.
 * - Enqueuing a handle that is not registered yet does *not* put it in the
 *   queue. WP_Dependencies::enqueue() files it under $queued_before_register
 *   (class-wp-dependencies.php lines 391-394), and WP_Dependencies::add()
 *   replays it into the queue if and when the handle is registered (lines
 *   287-295). An earlier version of this stub queued it immediately and said
 *   that was "exactly as core does". It is not, and the difference was not
 *   cosmetic: it hid a critical ordering bug on block themes, because
 *   wp_script_is( ..., 'enqueued' ) answered true for a handle core would have
 *   left in limbo.
 * - 'enqueued' is not membership of the queue. WP_Dependencies::query() falls
 *   through to recurse_deps() (lines 483-488), so a handle that is merely a
 *   dependency of something enqueued answers true as well.
 *
 * Deliberately not modelled, and each omission is something a test must not
 * lean on:
 *
 * - Printing. Nothing here resolves dependencies, computes groups or emits a
 *   tag, so no test can prove output order by running these. What a test can
 *   prove is what was *declared*, which is the part the plugin controls.
 * - _wp_scripts_maybe_doing_it_wrong(), which on a real site scolds a
 *   registration made before init.
 * - Concatenation, the script_loader_tag filter, conditional comments and
 *   inline scripts.
 * ---------------------------------------------------------------------------
 */

if ( ! function_exists( 'slosm_stub_queue_dependency' ) ) {
	/**
	 * Whether a handle is reachable through the dependencies of a queue.
	 *
	 * WP_Dependencies::recurse_deps(), which is what makes query( $handle,
	 * 'enqueued' ) answer true for a library nothing enqueued directly.
	 *
	 * @param array    $registry Registered items, keyed by handle.
	 * @param string[] $queue    Handles actually in the queue.
	 * @param string   $handle   Handle to look for.
	 * @return bool
	 */
	function slosm_stub_queue_dependency( array $registry, array $queue, string $handle ): bool {
		$seen  = array();
		$stack = $queue;

		while ( $stack ) {
			$current = array_pop( $stack );

			if ( isset( $seen[ $current ] ) ) {
				continue;
			}

			$seen[ $current ] = true;

			foreach ( $registry[ $current ]['deps'] ?? array() as $dep ) {
				if ( $dep === $handle ) {
					return true;
				}

				$stack[] = $dep;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wp_script_add_data' ) ) {
	/**
	 * Attaches one piece of metadata to a registered script.
	 *
	 * Core's validation of the 'strategy' key is modelled rather than skipped,
	 * and that is the one place this stub is deliberately strict.
	 * WP_Scripts::add_data() -- the override added in 6.3.0 -- answers a strategy
	 * that is neither 'defer' nor 'async' with _doing_it_wrong() and false, and
	 * does the same for a delayed strategy on a handle registered with no src,
	 * since an alias has no tag to put the attribute on. A stub that accepted
	 * both would let a typo'd strategy pass here and scold on a real site.
	 *
	 * @param string $handle Script handle.
	 * @param string $key    Data key.
	 * @param mixed  $value  Data value.
	 * @return bool False when the handle is not registered, or core would refuse.
	 */
	function wp_script_add_data( $handle, $key, $value ) {
		if ( ! isset( $GLOBALS['slosm_stub']['scripts'][ $handle ] ) ) {
			return false;
		}

		if ( 'strategy' === $key ) {
			if ( ! in_array( $value, array( 'defer', 'async' ), true ) ) {
				return false;
			}

			if ( ! $GLOBALS['slosm_stub']['scripts'][ $handle ]['src'] ) {
				return false;
			}
		}

		$GLOBALS['slosm_stub']['scripts'][ $handle ]['extra'][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	/**
	 * Attaches a snippet of JavaScript to a registered handle.
	 *
	 * Modelled on WP_Scripts::add_inline_script() (class-wp-scripts.php lines
	 * 491-504 of WordPress 6.9.1) and the wrapper above it: an empty string is
	 * refused outright, anything that is not 'after' is 'before', and the
	 * snippets accumulate in an array under that key rather than replacing one
	 * another.
	 *
	 * The false on an unregistered handle is the part worth having. Core's
	 * WP_Dependencies::add_data() returns false on its first line for a handle
	 * it does not know, so an inline script attached before its handle has been
	 * registered is discarded in silence -- which is a Bricks element whose
	 * re-render hook is simply never defined, with nothing anywhere to say so.
	 *
	 * Not modelled: the </script> check and its _doing_it_wrong(), and the
	 * position-aware printing that WordPress 6.3 added for deferred handles.
	 *
	 * @param string $handle   Script handle.
	 * @param string $data     The JavaScript.
	 * @param string $position 'after' or 'before'.
	 * @return bool Whether it was attached.
	 */
	function wp_add_inline_script( $handle, $data, $position = 'after' ) {
		if ( ! $data ) {
			return false;
		}

		if ( ! isset( $GLOBALS['slosm_stub']['scripts'][ $handle ] ) ) {
			return false;
		}

		if ( 'after' !== $position ) {
			$position = 'before';
		}

		$GLOBALS['slosm_stub']['scripts'][ $handle ]['extra'][ $position ][] = $data;

		return true;
	}
}

if ( ! function_exists( 'wp_register_script' ) ) {
	/**
	 * Records a script registration.
	 *
	 * @param string $handle Script handle.
	 * @param mixed  $src    Url, or false for an alias.
	 * @param array  $deps   Handles this one depends on.
	 * @param mixed  $ver    Version; false means "use WordPress's own", null means none.
	 * @param mixed  $args   Array of loading arguments, or a boolean $in_footer.
	 * @return bool Whether this call is what registered the handle.
	 */
	function wp_register_script( $handle, $src, $deps = array(), $ver = false, $args = array() ) {
		$GLOBALS['slosm_stub']['script_args'][ $handle ][] = $args;

		if ( ! is_array( $args ) ) {
			$args = array( 'in_footer' => (bool) $args );
		}

		$registered = ! isset( $GLOBALS['slosm_stub']['scripts'][ $handle ] );

		if ( $registered ) {
			$GLOBALS['slosm_stub']['scripts'][ $handle ] = array(
				'src'   => $src,
				'deps'  => (array) $deps,
				'ver'   => $ver,
				'extra' => array(),
				'l10n'  => array(),
			);
		}

		if ( ! empty( $args['in_footer'] ) ) {
			wp_script_add_data( $handle, 'group', 1 );
		}

		if ( ! empty( $args['strategy'] ) ) {
			wp_script_add_data( $handle, 'strategy', $args['strategy'] );
		}

		// Anything that asked for this handle before it existed is queued now.
		if ( $registered && array_key_exists( $handle, $GLOBALS['slosm_stub']['script_early'] ) ) {
			unset( $GLOBALS['slosm_stub']['script_early'][ $handle ] );

			wp_enqueue_script( $handle );
		}

		return $registered;
	}
}

if ( ! function_exists( 'wp_register_style' ) ) {
	/**
	 * Records a stylesheet registration.
	 *
	 * The fifth argument is $media, not $in_footer: styles have no groups of
	 * their own to be put in, and WP_Styles::do_item() ignores the group it is
	 * handed entirely -- which is why a stylesheet enqueued too late for the head
	 * is printed by print_late_styles() in the footer rather than dropped.
	 *
	 * @param string $handle Style handle.
	 * @param mixed  $src    Url, or false for an alias.
	 * @param array  $deps   Handles this one depends on.
	 * @param mixed  $ver    Version.
	 * @param string $media  Media query.
	 * @return bool Whether this call is what registered the handle.
	 */
	function wp_register_style( $handle, $src, $deps = array(), $ver = false, $media = 'all' ) {
		if ( isset( $GLOBALS['slosm_stub']['styles'][ $handle ] ) ) {
			return false;
		}

		$GLOBALS['slosm_stub']['styles'][ $handle ] = array(
			'src'   => $src,
			'deps'  => (array) $deps,
			'ver'   => $ver,
			'media' => $media,
		);

		if ( array_key_exists( $handle, $GLOBALS['slosm_stub']['style_early'] ) ) {
			unset( $GLOBALS['slosm_stub']['style_early'][ $handle ] );

			wp_enqueue_style( $handle );
		}

		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	/**
	 * Puts a handle in the script queue, registering it first when given a src.
	 *
	 * An unregistered handle does not reach the queue. It is held aside, the way
	 * WP_Dependencies::enqueue() holds it in $queued_before_register
	 * (class-wp-dependencies.php lines 391-394), and wp_register_script() above
	 * releases it when and if the handle is registered.
	 *
	 * That distinction is the whole reason this stub was rewritten. Queuing it
	 * immediately makes wp_script_is( $handle, 'enqueued' ) answer true for a
	 * handle WordPress has not accepted yet, and every guard written against
	 * that question then short-circuits on a request where nothing has been
	 * registered — which is precisely the block-theme ordering this suite could
	 * not see.
	 *
	 * @param string $handle Script handle.
	 * @param string $src    Url; when given, the handle is registered too.
	 * @param array  $deps   Handles this one depends on.
	 * @param mixed  $ver    Version.
	 * @param mixed  $args   Array of loading arguments, or a boolean $in_footer.
	 * @return void
	 */
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
		if ( $src ) {
			wp_register_script( $handle, $src, $deps, $ver, $args );
		}

		if ( ! isset( $GLOBALS['slosm_stub']['scripts'][ $handle ] ) ) {
			$GLOBALS['slosm_stub']['script_early'][ $handle ] = null;

			return;
		}

		if ( ! in_array( $handle, $GLOBALS['slosm_stub']['script_queue'], true ) ) {
			$GLOBALS['slosm_stub']['script_queue'][] = $handle;
		}
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	/**
	 * Puts a handle in the style queue, registering it first when given a src.
	 *
	 * @param string $handle Style handle.
	 * @param string $src    Url; when given, the handle is registered too.
	 * @param array  $deps   Handles this one depends on.
	 * @param mixed  $ver    Version.
	 * @param string $media  Media query.
	 * @return void
	 */
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		if ( $src ) {
			wp_register_style( $handle, $src, $deps, $ver, $media );
		}

		if ( ! isset( $GLOBALS['slosm_stub']['styles'][ $handle ] ) ) {
			$GLOBALS['slosm_stub']['style_early'][ $handle ] = null;

			return;
		}

		if ( ! in_array( $handle, $GLOBALS['slosm_stub']['style_queue'], true ) ) {
			$GLOBALS['slosm_stub']['style_queue'][] = $handle;
		}
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	/**
	 * Records an attempt to attach a data object to a script handle.
	 *
	 * Every call is recorded, including one that attaches nothing because the
	 * handle is not registered, because "how many times was this called" is what
	 * a test about two locators on one page needs -- core concatenates rather
	 * than replaces, so two calls are two variable declarations in the page.
	 *
	 * Not modelled: the html_entity_decode() core runs over scalar values, the
	 * 'l10n_print_after' back-compat key, the jquery/jquery-core rename and the
	 * json encoding.
	 *
	 * @param string $handle      Script handle.
	 * @param string $object_name Name of the JavaScript variable.
	 * @param mixed  $l10n        Data.
	 * @return bool False when the handle is not registered.
	 */
	function wp_localize_script( $handle, $object_name, $l10n ) {
		$GLOBALS['slosm_stub']['localize_calls'][] = array(
			'handle' => $handle,
			'object' => $object_name,
			'l10n'   => $l10n,
		);

		if ( ! isset( $GLOBALS['slosm_stub']['scripts'][ $handle ] ) ) {
			return false;
		}

		$GLOBALS['slosm_stub']['scripts'][ $handle ]['l10n'][] = array(
			'object' => $object_name,
			'data'   => $l10n,
		);

		/*
		 * And the same thing again in the shape core keeps it in, because that
		 * is the copy anything reading it back has to find.
		 * WP_Scripts::localize() builds one `var name = json;` statement and
		 * hands it to add_data( $handle, 'data', ... ), prepending whatever was
		 * there before rather than replacing it — which is the concatenation
		 * that makes a second call a second statement in the page.
		 */
		$statement = 'var ' . $object_name . ' = ' . json_encode( $l10n, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ';';
		$existing  = $GLOBALS['slosm_stub']['scripts'][ $handle ]['extra']['data'] ?? '';

		$GLOBALS['slosm_stub']['scripts'][ $handle ]['extra']['data'] = '' === $existing
			? $statement
			: $existing . "\n" . $statement;

		return true;
	}
}

if ( ! class_exists( 'Slosm_Stub_Scripts' ) ) {
	/**
	 * The sliver of WP_Scripts this plugin reads back.
	 *
	 * One method, and it is the public reader WP_Dependencies has had since
	 * 2.6: get_data( $handle, $key ) answers with whatever add_data() put
	 * there, or false. Production code asks it whether the localized payload is
	 * already attached to a handle, which is a question about core's own state
	 * and so has to be answered out of the same store wp_localize_script()
	 * writes to — the 'extra' array above, not the structured 'l10n' record
	 * this suite keeps alongside it for assertions.
	 *
	 * Everything else WP_Scripts does is absent, deliberately: there is no
	 * printing here, no queue resolution, no default scripts. A test that
	 * reached for any of it would be testing this class rather than the plugin.
	 */
	class Slosm_Stub_Scripts {

		/**
		 * Reads one piece of metadata off a registered handle.
		 *
		 * @param string $handle Script handle.
		 * @param string $key    Data key.
		 * @return mixed False when the handle is unknown or carries nothing under that key.
		 */
		public function get_data( $handle, $key ) {
			if ( ! isset( $GLOBALS['slosm_stub']['scripts'][ $handle ]['extra'][ $key ] ) ) {
				return false;
			}

			return $GLOBALS['slosm_stub']['scripts'][ $handle ]['extra'][ $key ];
		}
	}
}

if ( ! function_exists( 'wp_scripts' ) ) {
	/**
	 * The global script registry.
	 *
	 * Core memoises a WP_Scripts in a global and builds one on first use, which
	 * fires wp_default_scripts and registers the whole of core's own library. A
	 * fresh reader every call is fine here because it holds no state of its own:
	 * everything it answers comes out of $GLOBALS['slosm_stub'], which it() is
	 * what resets.
	 *
	 * @return Slosm_Stub_Scripts
	 */
	function wp_scripts() {
		return new Slosm_Stub_Scripts();
	}
}

if ( ! function_exists( 'wp_set_script_translations' ) ) {
	/**
	 * Records a request to load JavaScript translations for a handle.
	 *
	 * Recorded rather than modelled because the one thing it does that matters
	 * is a side effect a recorder cannot show: WP_Scripts::set_translations()
	 * appends 'wp-i18n' to the handle's dependencies (class-wp-scripts.php line
	 * 698 in 6.9.1), so calling it makes wp-i18n load on every page carrying a
	 * locator. A case in tests/test-assets.php asserts this is not called.
	 *
	 * @param string $handle Script handle.
	 * @param string $domain Text domain.
	 * @param string $path   Directory holding the json translation files.
	 * @return bool
	 */
	function wp_set_script_translations( $handle, $domain = 'default', $path = '' ) {
		$GLOBALS['slosm_stub']['translation_calls'][] = array(
			'handle' => $handle,
			'domain' => $domain,
			'path'   => $path,
		);

		return isset( $GLOBALS['slosm_stub']['scripts'][ $handle ] );
	}
}

if ( ! function_exists( 'wp_script_is' ) ) {
	/**
	 * Whether a script handle is registered, or enqueued.
	 *
	 * 'enqueued' is deliberately not "is in the queue". WP_Dependencies::query()
	 * falls through to recurse_deps() for that status
	 * (class-wp-dependencies.php lines 483-488), so a handle that nothing
	 * enqueued but something enqueued depends on answers true. Modelled because
	 * the difference bites: a third-party script declaring slosm-locator as a
	 * dependency would make this true for slosm-locator before this plugin has
	 * enqueued anything at all, and a guard written against it would then skip
	 * work it had not done.
	 *
	 * Only two of core's four statuses are answerable here. 'done' and 'to_do'
	 * describe a print pass, and nothing in this stub prints, so both are always
	 * false -- which is faithful rather than convenient, since nothing has in
	 * fact been printed.
	 *
	 * @param string $handle Script handle.
	 * @param string $status 'registered', 'enqueued' or 'queue'.
	 * @return bool
	 */
	function wp_script_is( $handle, $status = 'enqueued' ) {
		if ( 'registered' === $status || '' === $status ) {
			return isset( $GLOBALS['slosm_stub']['scripts'][ $handle ] );
		}

		if ( 'enqueued' === $status || 'queue' === $status ) {
			if ( in_array( $handle, $GLOBALS['slosm_stub']['script_queue'], true ) ) {
				return true;
			}

			return slosm_stub_queue_dependency(
				$GLOBALS['slosm_stub']['scripts'],
				$GLOBALS['slosm_stub']['script_queue'],
				(string) $handle
			);
		}

		return false;
	}
}

if ( ! function_exists( 'wp_style_is' ) ) {
	/**
	 * Whether a style handle is registered, or in the queue.
	 *
	 * @param string $handle Style handle.
	 * @param string $status 'registered', 'enqueued' or 'queue'.
	 * @return bool
	 */
	function wp_style_is( $handle, $status = 'enqueued' ) {
		if ( 'registered' === $status || '' === $status ) {
			return isset( $GLOBALS['slosm_stub']['styles'][ $handle ] );
		}

		if ( 'enqueued' === $status || 'queue' === $status ) {
			if ( in_array( $handle, $GLOBALS['slosm_stub']['style_queue'], true ) ) {
				return true;
			}

			return slosm_stub_queue_dependency(
				$GLOBALS['slosm_stub']['styles'],
				$GLOBALS['slosm_stub']['style_queue'],
				(string) $handle
			);
		}

		return false;
	}
}

/*
 * The admin screens' half of WordPress.
 *
 * Everything below arrived with Task 17, which is the first code under admin/.
 * The same rule as above applies to all of it: a recorder where the plugin
 * controls the arguments, a model where the plugin depends on the behaviour,
 * and a docblock naming what is not modelled either way.
 */

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Strips one layer of slashes, from strings anywhere in the value.
	 *
	 * Core is wp_unslash() -> stripslashes_deep() -> map_deep() with
	 * stripslashes_from_strings_only(), so non-strings pass through untouched
	 * and arrays are walked. Verified in WordPress 6.9.1,
	 * wp-includes/formatting.php lines 5813 and 2860.
	 *
	 * This exists because $_POST really is slashed on every request:
	 * wp_magic_quotes() at wp-includes/load.php line 1288 runs add_magic_quotes()
	 * over it before a single plugin loads. A save handler that reads $_POST
	 * without this stores a doubled backslash for every apostrophe an editor
	 * types.
	 *
	 * @param mixed $value Value to unslash.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}

		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	/**
	 * Adds one layer of slashes, to strings anywhere in the value.
	 *
	 * Core, verbatim, from wp-includes/formatting.php line 5790 of 6.9.1:
	 * arrays are mapped, strings are addslashes()'d, everything else is
	 * returned as it came.
	 *
	 * The pair is exact — stripslashes( addslashes( $s ) ) === $s for every
	 * string — which is what makes "unslash, sanitise, slash again" a lossless
	 * pipeline rather than a hopeful one.
	 *
	 * @param mixed $value Value to slash.
	 * @return mixed
	 */
	function wp_slash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_slash', $value );
		}

		return is_string( $value ) ? addslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	/**
	 * Escapes a value for the inside of a textarea.
	 *
	 * htmlspecialchars() with ENT_QUOTES and nothing else, which is core's own
	 * body at wp-includes/formatting.php line 4739. Newlines survive, and that
	 * is the point of having it separate from esc_attr() here: the opening-hours
	 * field is the one value in this plugin whose newlines mean something.
	 *
	 * Not modelled: the blog_charset option and the esc_textarea filter.
	 *
	 * @param mixed $text Value.
	 * @return string
	 */
	function esc_textarea( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * A nonce for an action, deterministic and readable.
	 *
	 * Not a hash. A real nonce depends on a user, a session token and a
	 * twelve-hour tick, none of which exist here, and a stub that hashed
	 * something would only be unreadable rather than realistic. What a test
	 * needs is a value wp_verify_nonce() below agrees with and a person can see
	 * in a failure message.
	 *
	 * @param string|int $action Action the nonce is for.
	 * @return string
	 */
	function wp_create_nonce( $action = -1 ) {
		return 'nonce:' . $action;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Verifies a nonce, answering 1, 2 or false exactly as core does.
	 *
	 * The three-valued return is the whole reason this is modelled rather than
	 * faked with a boolean. wp-includes/pluggable.php lines 2493-2501 of
	 * WordPress 6.9.1 return int 1 for a nonce from the current twelve-hour
	 * tick, int 2 for one from the tick before, and false otherwise. A handler
	 * written as `if ( true === wp_verify_nonce( ... ) )` therefore refuses
	 * every save from a form that has been open for twelve hours — silently,
	 * because there is nothing to see except that the fields did not change.
	 *
	 * So this stub gives tests both live answers: 'nonce:<action>' verifies as
	 * 1, and 'stale:<action>' as 2, standing for the form left open overnight.
	 *
	 * Not modelled: the user, the session token, the real tick, and the
	 * wp_verify_nonce_failed action.
	 *
	 * @param mixed      $nonce  Nonce from the request.
	 * @param string|int $action Action it should be for.
	 * @return int|false 1, 2, or false.
	 */
	function wp_verify_nonce( $nonce, $action = -1 ) {
		$nonce = (string) $nonce;

		if ( '' === $nonce ) {
			return false;
		}

		if ( 'nonce:' . $action === $nonce ) {
			return 1;
		}

		if ( 'stale:' . $action === $nonce ) {
			return 2;
		}

		return false;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	/**
	 * Prints, and returns, the hidden nonce input.
	 *
	 * Core's shape, from wp-includes/functions.php: the id and the name are both
	 * $name, and the referer field follows unless $referer is false. The referer
	 * field is a literal here rather than wp_referer_field(), which would want a
	 * $_SERVER this suite does not stage.
	 *
	 * @param string|int $action  Action.
	 * @param string     $name    Field name.
	 * @param bool       $referer Whether to add the referer field.
	 * @param bool       $display Whether to echo it.
	 * @return string
	 */
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
		$name  = esc_attr( $name );
		$field = '<input type="hidden" id="' . $name . '" name="' . $name . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';

		if ( $referer ) {
			$field .= '<input type="hidden" name="_wp_http_referer" value="" />';
		}

		if ( $display ) {
			echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		return $field;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Answers from what a test staged, and says no by default.
	 *
	 * No by default, and that direction is deliberate. A stub that said yes
	 * unless told otherwise would make every capability check in the plugin
	 * unprovable: deleting the check would leave every case green, because
	 * every case would have been running as a user who could do everything
	 * anyway. Saying no means a case that wants a save to happen has to grant
	 * the capability, and the "no capability" case is then the same fixture with
	 * one line removed.
	 *
	 * Every call is recorded, capability and arguments both, so a case can prove
	 * the plugin asked about the right post rather than merely asking.
	 *
	 * Meta capabilities are not mapped: 'edit_post' with a post id is whatever
	 * the test staged under the key 'edit_post', and map_meta_cap() does not
	 * exist here.
	 *
	 * A staged value that is an *array* is the list of object ids the capability
	 * is granted for, and anything else truthy grants it for everything. That is
	 * the whole of what this stub knows about meta capabilities, and it exists
	 * for one shape of case that cannot be written without it: a bulk action
	 * over several locations, where the point is that the ones this person may
	 * not edit are refused and named while the rest go through. A boolean can
	 * only make every location refused or none, which cannot tell "checks per
	 * location" from "checks once".
	 *
	 * @param string $capability Capability.
	 * @param mixed  ...$args    Object id and anything else.
	 * @return bool
	 */
	function current_user_can( $capability, ...$args ) {
		$GLOBALS['slosm_stub']['cap_checks'][] = array(
			'capability' => $capability,
			'args'       => $args,
		);

		$staged = $GLOBALS['slosm_stub']['capabilities'][ $capability ] ?? null;

		if ( is_array( $staged ) ) {
			return isset( $args[0] ) && in_array( (int) $args[0], array_map( 'intval', $staged ), true );
		}

		return ! empty( $staged );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * The current user id, zero unless a test staged one.
	 *
	 * Zero is what WordPress answers for a logged-out visitor, so it is the
	 * honest default: code that keys anything on the user has to notice.
	 *
	 * @return int
	 */
	function get_current_user_id() {
		return (int) ( $GLOBALS['slosm_stub']['current_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'add_meta_box' ) ) {
	/**
	 * Records a metabox registration.
	 *
	 * A recorder, not an implementation: there is no $wp_meta_boxes here, no
	 * screen object and no do_meta_boxes(). What the plugin controls is the
	 * seven arguments, and that is what a case asserts on — the same
	 * arrangement as register_post_type() above.
	 *
	 * Every call is appended rather than keyed by id, so a case can tell one
	 * registration from two.
	 *
	 * @param string   $id            Metabox id.
	 * @param string   $title         Title shown to the editor.
	 * @param callable $callback      Renders the box.
	 * @param mixed    $screen        Screen, post type or null.
	 * @param string   $context       'normal', 'side' or 'advanced'.
	 * @param string   $priority      'high', 'default' or 'low'.
	 * @param mixed    $callback_args Passed through to the callback.
	 * @return void
	 */
	function add_meta_box( $id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default', $callback_args = null ) {
		$GLOBALS['slosm_stub']['meta_boxes'][] = array(
			'id'            => $id,
			'title'         => $title,
			'callback'      => $callback,
			'screen'        => $screen,
			'context'       => $context,
			'priority'      => $priority,
			'callback_args' => $callback_args,
		);
	}
}

if ( ! function_exists( 'get_current_screen' ) ) {
	/**
	 * The screen a test staged, or null.
	 *
	 * Null is the honest default and not an oversight. get_current_screen()
	 * really does return null before set_current_screen() has run — it is
	 * documented as returning WP_Screen|null, and wp-includes/ has callers
	 * guarding on exactly that — so any code that reads ->post_type off the
	 * result without checking is one admin-ajax request away from a fatal.
	 * A stub that always answered with an object would make that guard
	 * untestable.
	 *
	 * What is staged is a plain object with whatever members a case needs,
	 * because WP_Screen is final-ish in spirit, has a private constructor
	 * (WP_Screen::get() is the only way to build one) and carries thirty
	 * members none of this plugin reads. The two it does read are `base` and
	 * `post_type`.
	 *
	 * @return object|null
	 */
	function get_current_screen() {
		return $GLOBALS['slosm_stub']['current_screen'];
	}
}

if ( ! function_exists( 'slosm_stub_screen' ) ) {
	/**
	 * Stages one admin screen.
	 *
	 * @param string $base      Screen base: 'post' for the edit form, 'edit' for the list table.
	 * @param string $post_type Post type the screen is about.
	 * @return object The screen, so a case can alter it.
	 */
	function slosm_stub_screen( string $base, string $post_type ): object {
		$screen = (object) array(
			'base'      => $base,
			'post_type' => $post_type,
			'id'        => 'post' === $base ? $post_type : $base . '-' . $post_type,
		);

		$GLOBALS['slosm_stub']['current_screen'] = $screen;

		return $screen;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Adds one query argument to a url.
	 *
	 * The three-argument form only — add_query_arg( $key, $value, $url ) —
	 * because that is the only shape this plugin uses. Core's function also
	 * takes an array, and takes no url at all (in which case it reads
	 * $_SERVER['REQUEST_URI']), and both of those are deliberately left out:
	 * a stub that accepted them would accept a call this plugin must never
	 * make, since the no-url form on a REST or cron request builds a string
	 * out of whatever the front controller happened to be.
	 *
	 * The fragment is preserved on the far side of the query, which is what
	 * core does — it splits on '#' first — and is the whole reason this is a
	 * function rather than a concatenation. Values are urlencoded, as
	 * build_query() does for the arguments it adds.
	 *
	 * An argument the url already carries is *replaced* rather than appended a
	 * second time, which is what core does — it parses the query, assigns into
	 * it and rebuilds — and modelling it is not fussiness. Task 20 adds the
	 * unplaced view's argument to a redirect url that core may already have
	 * built out of the referer carrying it, so a stub that appended would make a
	 * case assert a doubled argument that no site would ever produce.
	 *
	 * Where it still diverges: core rebuilds the whole query string, so the
	 * replaced argument keeps its original *position* and every other argument
	 * is re-encoded. This removes the old occurrences and appends, which leaves
	 * the other arguments byte for byte as they were and moves the replaced one
	 * to the end.
	 *
	 * Not modelled: the array form, the REQUEST_URI form, the relative-vs-
	 * absolute handling, and the '?'-only url.
	 *
	 * @param string $key   Argument name.
	 * @param string $value Argument value.
	 * @param string $url   Url to add it to.
	 * @return string
	 */
	function add_query_arg( $key, $value, $url ) {
		$url      = (string) $url;
		$fragment = '';
		$hash     = strpos( $url, '#' );

		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}

		$key   = (string) $key;
		$mark  = strpos( $url, '?' );
		$query = false === $mark ? '' : substr( $url, $mark + 1 );
		$base  = false === $mark ? $url : substr( $url, 0, $mark );

		$kept = array();

		foreach ( '' === $query ? array() : explode( '&', $query ) as $pair ) {
			$name = false === strpos( $pair, '=' ) ? $pair : substr( $pair, 0, strpos( $pair, '=' ) );

			if ( rawurldecode( $name ) !== $key ) {
				$kept[] = $pair;
			}
		}

		$kept[] = rawurlencode( $key ) . '=' . rawurlencode( (string) $value );

		return $base . '?' . implode( '&', $kept ) . $fragment;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * Whether this request is an admin one.
	 *
	 * False unless a test says otherwise, and that direction is the same
	 * decision current_user_can() makes. A stub answering true by default would
	 * make every is_admin() gate in the plugin unprovable: deleting the gate
	 * would leave every case green, because every case would already be running
	 * as though it were in wp-admin. Saying false means a case about an admin
	 * screen has to say so, and the front-end case is the same fixture with one
	 * line removed.
	 *
	 * Core reads WP_ADMIN, a constant, which cannot be moved inside a running
	 * process; a stub reading it would be a stub no case could set up twice.
	 *
	 * @return bool
	 */
	function is_admin() {
		return ! empty( $GLOBALS['slosm_stub']['is_admin'] );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * The url of an admin screen.
	 *
	 * Core is get_admin_url(), which is the 'siteurl' option plus '/wp-admin/'
	 * plus the path, with a scheme fixup and a filter. This reads the same
	 * option so that a case which cares can set it.
	 *
	 * Not modelled: the scheme fixup, multisite, and the 'admin_url' filter.
	 *
	 * @param string $path   Path within wp-admin.
	 * @param string $scheme Ignored.
	 * @return string
	 */
	function admin_url( $path = '', $scheme = 'admin' ) {
		$url = rtrim( (string) get_option( 'siteurl', 'https://example.test' ), '/' ) . '/wp-admin/';

		return '' === (string) $path ? $url : $url . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Escapes a url for printing into an attribute.
	 *
	 * esc_url_raw() first, so the protocol allowlist is the same one the
	 * database-bound sanitiser applies, and then the two substitutions core's
	 * 'display' context makes: '&' becomes '&#038;' and an apostrophe becomes
	 * '&#039;' (wp-includes/formatting.php, clean_url()). Those two are what
	 * separate a url printed into href="" from one written to storage, and they
	 * are the whole reason this is not an alias of esc_url_raw().
	 *
	 * Not modelled: the entity decoding core does first, the character
	 * stripping, and the 'clean_url' filter.
	 *
	 * @param mixed      $url       Url.
	 * @param array|null $protocols Acceptable protocols; null means core's full list.
	 * @param string     $_context  Ignored; this stub is always 'display'.
	 * @return string
	 */
	function esc_url( $url, $protocols = null, $_context = 'display' ) {
		$url = esc_url_raw( $url, $protocols );

		if ( '' === $url ) {
			return '';
		}

		return str_replace( array( '&', "'" ), array( '&#038;', '&#039;' ), $url );
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Enough of WP_Query for a pre_get_posts callback to be exercised.
	 *
	 * Three members and nothing else: the query vars, get()/set() over them, and
	 * whether this is the main query. A pre_get_posts callback touches exactly
	 * those, and everything else WP_Query does — parsing, the SQL, the loop — is
	 * on the far side of the hook.
	 *
	 * get()'s default is '' rather than null, which is core's
	 * (wp-includes/class-wp-query.php), and it matters: a callback comparing
	 * get( 'post_type' ) against a string has to see '' for an unset var rather
	 * than null, or a `=== ''` check would pass here and fail on a site.
	 *
	 * is_main_query() is a property a case sets, not a global comparison. Core
	 * compares against $GLOBALS['wp_the_query'], which this suite does not have
	 * and which would make the flag a fact about the suite rather than about the
	 * fixture.
	 */
	class WP_Query {

		/**
		 * The query vars.
		 *
		 * @var array
		 */
		public $query_vars = array();

		/**
		 * Whether this is the request's main query.
		 *
		 * @var bool
		 */
		public $is_main = false;

		/**
		 * Builds a query over some vars.
		 *
		 * @param array $vars Query vars.
		 * @param bool  $main Whether this is the main query.
		 */
		public function __construct( $vars = array(), $main = false ) {
			$this->query_vars = is_array( $vars ) ? $vars : array();
			$this->is_main    = (bool) $main;
		}

		/**
		 * Reads one query var.
		 *
		 * @param string $key     Var name.
		 * @param mixed  $default Value when it is not set.
		 * @return mixed
		 */
		public function get( $key, $default = '' ) {
			return $this->query_vars[ $key ] ?? $default;
		}

		/**
		 * Writes one query var.
		 *
		 * @param string $key   Var name.
		 * @param mixed  $value Value.
		 * @return void
		 */
		public function set( $key, $value ) {
			$this->query_vars[ $key ] = $value;
		}

		/**
		 * Whether this is the request's main query.
		 *
		 * @return bool
		 */
		public function is_main_query() {
			return $this->is_main;
		}
	}
}

/*
 * ---------------------------------------------------------------------------
 * The admin-menu and Settings-API surface, added for Task 21.
 *
 * Recorders, in the same spirit as register_post_type() and add_meta_box()
 * above: what this plugin controls is the arguments it passes, and that is what
 * a case asserts on. Four of them do more than record, and each does it for a
 * reason a case depends on:
 *
 * - add_submenu_page() hands back a hook suffix in core's shape, because
 *   Assets::enqueue_admin() recognises this screen by that string.
 * - register_setting() really adds the sanitize filter, because that filter is
 *   the whole of "there is no second door".
 * - do_settings_sections() really walks what add_settings_section() and
 *   add_settings_field() recorded and calls the field callbacks, because the
 *   markup those callbacks print — the names, the escaping, the checkbox
 *   companions — is the whole of what the screen is.
 * - wp_die() throws, because "this request was refused" is the assertion, and a
 *   stub that returned would let a refused request carry on and write.
 * ---------------------------------------------------------------------------
 */

if ( ! class_exists( 'Slosm_Died' ) ) {
	/**
	 * What the wp_die() stub throws.
	 *
	 * A class of its own rather than RuntimeException so a case can tell "the
	 * request was refused" from "something else went wrong", which is exactly
	 * the distinction a security case is making.
	 */
	class Slosm_Died extends Exception {
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * Ends the request, which here means throwing.
	 *
	 * @param string $message Message.
	 * @param mixed  $title   Title, or a status code as core allows.
	 * @param mixed  $args    Arguments.
	 * @return void
	 * @throws Slosm_Died Always.
	 */
	function wp_die( $message = '', $title = '', $args = array() ) {
		$GLOBALS['slosm_stub']['died'][] = array(
			'message' => (string) $message,
			'title'   => $title,
			'args'    => $args,
		);

		throw new Slosm_Died( (string) $message );
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	/**
	 * Records a submenu registration and answers with core's hook suffix.
	 *
	 * The hook suffix is `{page_type}_page_{slug}` — get_plugin_page_hookname(),
	 * wp-admin/includes/plugin.php lines 2158-2177 of WordPress 6.9.1 — where
	 * the page type for a parent of 'edit.php?post_type=slosm_store' is the post
	 * type, because that is what $admin_page_hooks holds for a post type's menu.
	 * That much is modelled because Assets::enqueue_admin() recognises the
	 * settings screen by the end of this string.
	 *
	 * Not modelled: $admin_page_hooks itself, the capability filtering of the
	 * menu, the position argument, and the false core returns to a user who may
	 * not see the page. The last of those is deliberate — a stub that returned
	 * false for an uncapable user would make the capability argument look like
	 * this plugin's gate, when the gate is core's own
	 * user_can_access_admin_page() (wp-admin/includes/menu.php line 371).
	 *
	 * @param string   $parent_slug Parent menu.
	 * @param string   $page_title  Title tag.
	 * @param string   $menu_title  Menu label.
	 * @param string   $capability  Capability the page needs.
	 * @param string   $menu_slug   Slug.
	 * @param callable $callback    Renders the page.
	 * @param mixed    $position    Menu position.
	 * @return string The hook suffix.
	 */
	function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = null, $position = null ) {
		$type = 'admin';

		if ( preg_match( '/post_type=([a-z0-9_\-]+)/i', (string) $parent_slug, $match ) ) {
			$type = $match[1];
		}

		$hook = $type . '_page_' . preg_replace( '!\.php!', '', (string) $menu_slug );

		$GLOBALS['slosm_stub']['admin_pages'][] = array(
			'parent_slug' => $parent_slug,
			'page_title'  => $page_title,
			'menu_title'  => $menu_title,
			'capability'  => $capability,
			'menu_slug'   => $menu_slug,
			'callback'    => $callback,
			'position'    => $position,
			'hook'        => $hook,
		);

		return $hook;
	}
}

if ( ! function_exists( 'register_setting' ) ) {
	/**
	 * Records a setting registration and hangs its sanitiser where core does.
	 *
	 * The add_filter() is not decoration. register_setting() adds the callback
	 * to `sanitize_option_{$option_name}` (wp-includes/option.php line 3073 of
	 * WordPress 6.9.1), and update_option() runs sanitize_option() on every
	 * write (line 887) — so that filter is the only thing that makes the
	 * sanitiser run for WP-CLI, for an importer and for this plugin's own
	 * writes. Recording it here lets a case assert the registration exists
	 * rather than take a docblock's word for it.
	 *
	 * The stubbed update_option() above still does not call sanitize_option().
	 * Modelling that would mean modelling sanitize_option()'s whole switch, and
	 * every case in this suite that stages an option would then have its
	 * fixture rewritten on the way in.
	 *
	 * @param string $option_group Group.
	 * @param string $option_name  Option.
	 * @param array  $args         Arguments.
	 * @return void
	 */
	function register_setting( $option_group, $option_name, $args = array() ) {
		$GLOBALS['slosm_stub']['settings'][] = array(
			'group'  => $option_group,
			'option' => $option_name,
			'args'   => is_array( $args ) ? $args : array(),
		);

		if ( is_array( $args ) && ! empty( $args['sanitize_callback'] ) ) {
			add_filter( 'sanitize_option_' . $option_name, $args['sanitize_callback'] );
		}
	}
}

if ( ! function_exists( 'add_settings_section' ) ) {
	/**
	 * Records a settings section.
	 *
	 * @param string   $id       Section id.
	 * @param string   $title    Heading.
	 * @param callable $callback Prints an introduction, or null.
	 * @param string   $page     Page the section belongs to.
	 * @param array    $args     Arguments.
	 * @return void
	 */
	function add_settings_section( $id, $title, $callback, $page, $args = array() ) {
		$GLOBALS['slosm_stub']['settings_sections'][ $page ][ $id ] = array(
			'id'       => $id,
			'title'    => $title,
			'callback' => $callback,
			'args'     => $args,
		);
	}
}

if ( ! function_exists( 'add_settings_field' ) ) {
	/**
	 * Records a settings field.
	 *
	 * @param string   $id       Field id.
	 * @param string   $title    Label.
	 * @param callable $callback Prints the control.
	 * @param string   $page     Page.
	 * @param string   $section  Section.
	 * @param array    $args     Arguments handed to the callback.
	 * @return void
	 */
	function add_settings_field( $id, $title, $callback, $page, $section = 'default', $args = array() ) {
		$GLOBALS['slosm_stub']['settings_field_list'][ $page ][ $section ][] = array(
			'id'       => $id,
			'title'    => $title,
			'callback' => $callback,
			'args'     => is_array( $args ) ? $args : array(),
		);
	}
}

if ( ! function_exists( 'do_settings_sections' ) ) {
	/**
	 * Prints one page's sections and fields, in core's shape.
	 *
	 * Core's markup, including the two places it prints a title **unescaped** —
	 * `echo "<h2>{$section['title']}</h2>"` and
	 * `echo '<th scope="row">' . $field['title'] . '</th>'`, in
	 * wp-admin/includes/template.php of WordPress 6.9.1. Reproduced rather than
	 * tidied, because a plugin passing an unescaped title there is a plugin
	 * with an admin-side injection and a stub that escaped for it would hide
	 * that.
	 *
	 * @param string $page Page.
	 * @return void
	 */
	function do_settings_sections( $page ) {
		$sections = $GLOBALS['slosm_stub']['settings_sections'][ $page ] ?? array();

		foreach ( $sections as $section ) {
			if ( $section['title'] ) {
				echo '<h2>' . $section['title'] . "</h2>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			if ( $section['callback'] ) {
				call_user_func( $section['callback'], $section );
			}

			$fields = $GLOBALS['slosm_stub']['settings_field_list'][ $page ][ $section['id'] ] ?? array();

			if ( array() === $fields ) {
				continue;
			}

			echo '<table class="form-table" role="presentation">';

			foreach ( $fields as $field ) {
				$class = empty( $field['args']['class'] ) ? '' : ' class="' . esc_attr( $field['args']['class'] ) . '"';

				echo '<tr' . $class . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

				if ( ! empty( $field['args']['label_for'] ) ) {
					echo '<th scope="row"><label for="' . esc_attr( $field['args']['label_for'] ) . '">' . $field['title'] . '</label></th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo '<th scope="row">' . $field['title'] . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}

				echo '<td>';
				call_user_func( $field['callback'], $field['args'] );
				echo '</td></tr>';
			}

			echo '</table>';
		}
	}
}

if ( ! function_exists( 'settings_fields' ) ) {
	/**
	 * Prints the three hidden inputs options.php needs.
	 *
	 * Core's own, verbatim: wp-admin/includes/plugin.php lines 2366-2370 of
	 * WordPress 6.9.1. The nonce action is `{$option_group}-options`, which is
	 * what options.php passes to check_admin_referer() at line 244 — so a case
	 * can assert that the form carries the credential the save will demand.
	 *
	 * @param string $option_group Group.
	 * @return void
	 */
	function settings_fields( $option_group ) {
		echo "<input type='hidden' name='option_page' value='" . esc_attr( $option_group ) . "' />";
		echo '<input type="hidden" name="action" value="update" />';
		wp_nonce_field( "$option_group-options" );
	}
}

if ( ! function_exists( '__checked_selected_helper' ) ) {
	/**
	 * Core's helper, including its string comparison.
	 *
	 * `(string) $helper === (string) $current` is what core does
	 * (wp-includes/general-template.php of WordPress 6.9.1), which is why
	 * selected( 0, '' ) is empty and selected( '5', 5 ) is not. Ported rather
	 * than approximated for that reason alone.
	 *
	 * @param mixed  $helper  One value.
	 * @param mixed  $current The other.
	 * @param bool   $display Whether to echo it.
	 * @param string $type    'checked' or 'selected'.
	 * @return string
	 */
	function __checked_selected_helper( $helper, $current, $display, $type ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
		$result = (string) $helper === (string) $current ? " $type='$type'" : '';

		if ( $display ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		return $result;
	}
}

if ( ! function_exists( 'checked' ) ) {
	/**
	 * Prints checked='checked' when two values match.
	 *
	 * @param mixed $checked One value.
	 * @param mixed $current The other.
	 * @param bool  $display Whether to echo it.
	 * @return string
	 */
	function checked( $checked, $current = true, $display = true ) {
		return __checked_selected_helper( $checked, $current, $display, 'checked' );
	}
}

if ( ! function_exists( 'selected' ) ) {
	/**
	 * Prints selected='selected' when two values match.
	 *
	 * @param mixed $selected One value.
	 * @param mixed $current  The other.
	 * @param bool  $display  Whether to echo it.
	 * @return string
	 */
	function selected( $selected, $current = true, $display = true ) {
		return __checked_selected_helper( $selected, $current, $display, 'selected' );
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	/**
	 * Records a redirect instead of sending one.
	 *
	 * @param string $location Where to.
	 * @param int    $status   Status code.
	 * @return bool
	 */
	function wp_safe_redirect( $location, $status = 302 ) {
		$GLOBALS['slosm_stub']['redirects'][] = array(
			'location' => (string) $location,
			'status'   => (int) $status,
		);

		return true;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * Formats a number for display.
	 *
	 * Core reads the thousands separator and decimal point out of the active
	 * locale's WP_Locale, and falls back to number_format() when there is no
	 * $wp_locale — which is what this is. Nothing in this plugin depends on the
	 * grouping character, and a stub that invented one would make a case assert
	 * a locale rather than a count.
	 *
	 * @param int|float $number   Number to format.
	 * @param int       $decimals Decimal places.
	 * @return string
	 */
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals );
	}
}

if ( ! function_exists( 'get_shortcode_atts_regex' ) ) {
	/**
	 * The pattern core splits a shortcode's attributes with.
	 *
	 * Verbatim from wp-includes/shortcodes.php line 594 of WordPress 6.9.1,
	 * character for character, and it has to be: the generator's whole claim is
	 * that the preview it prints is the parse of the string it printed, and a
	 * stub that parsed more permissively than core would make that claim pass
	 * here and fail on a site. Six alternatives, in order: name="value",
	 * name='value', name=value, "value", 'value', bare value.
	 *
	 * @return string
	 */
	function get_shortcode_atts_regex() {
		return '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';
	}
}

if ( ! function_exists( 'shortcode_parse_atts' ) ) {
	/**
	 * Splits the inside of a shortcode tag into attributes.
	 *
	 * A faithful reproduction of wp-includes/shortcodes.php lines 613-645 of
	 * WordPress 6.9.1, including the three parts of it that are easy to forget
	 * and that the generator's cases turn on:
	 *
	 * - Non-breaking and zero-width spaces are replaced with an ordinary space
	 *   *before* the pattern runs (line 616), so a label pasted out of a word
	 *   processor does not arrive as the editor typed it.
	 * - Every captured value goes through stripcslashes() (lines 620-630), which
	 *   is why a backslash cannot survive a shortcode attribute and why the
	 *   generator removes them.
	 * - A value containing an unclosed `<` is blanked outright (lines 635-641).
	 *
	 * Not modelled: nothing. This is the whole function.
	 *
	 * @param string $text The inside of an opening shortcode tag.
	 * @return array
	 */
	function shortcode_parse_atts( $text ) {
		$atts    = array();
		$pattern = get_shortcode_atts_regex();
		$text    = preg_replace( "/[\x{00a0}\x{200b}]+/u", ' ', $text );

		if ( preg_match_all( $pattern, $text, $match, PREG_SET_ORDER ) ) {
			foreach ( $match as $m ) {
				if ( ! empty( $m[1] ) ) {
					$atts[ strtolower( $m[1] ) ] = stripcslashes( $m[2] );
				} elseif ( ! empty( $m[3] ) ) {
					$atts[ strtolower( $m[3] ) ] = stripcslashes( $m[4] );
				} elseif ( ! empty( $m[5] ) ) {
					$atts[ strtolower( $m[5] ) ] = stripcslashes( $m[6] );
				} elseif ( isset( $m[7] ) && strlen( $m[7] ) ) {
					$atts[] = stripcslashes( $m[7] );
				} elseif ( isset( $m[8] ) && strlen( $m[8] ) ) {
					$atts[] = stripcslashes( $m[8] );
				} elseif ( isset( $m[9] ) ) {
					$atts[] = stripcslashes( $m[9] );
				}
			}

			foreach ( $atts as &$value ) {
				if ( false !== strpos( $value, '<' ) ) {
					if ( 1 !== preg_match( '/^[^<]*+(?:<[^>]*+>[^<]*+)*+$/', $value ) ) {
						$value = '';
					}
				}
			}
		}

		return $atts;
	}
}

if ( ! function_exists( 'get_shortcode_regex' ) ) {
	/**
	 * The pattern core finds a shortcode tag with.
	 *
	 * Verbatim from wp-includes/shortcodes.php lines 324-367 of WordPress
	 * 6.9.1, indentation and comments included, because the two facts the
	 * generator depends on are both inside it: group 3 — everything between the
	 * tag name and the closing bracket — admits no `]` at all, and the optional
	 * brackets in groups 1 and 6 are core's `[[escaped]]` form.
	 *
	 * The registered-tag fallback reads the same record add_shortcode() above
	 * writes, so a caller that passes no tag names gets the tags this test
	 * process registered. Every caller in this plugin passes them explicitly.
	 *
	 * @param string[]|null $tagnames Tags to match, or null for every registered one.
	 * @return string
	 */
	function get_shortcode_regex( $tagnames = null ) {
		if ( empty( $tagnames ) ) {
			$tagnames = array_keys( $GLOBALS['slosm_stub']['shortcodes'] ?? array() );
		}

		$tagregexp = implode( '|', array_map( 'preg_quote', $tagnames ) );

		return '\['                             // Opening bracket.
			. '(\[?)'                           // 1: Optional second opening bracket for escaping shortcodes: [[tag]].
			. "($tagregexp)"                     // 2: Shortcode name.
			. '(?![\w-])'                       // Not followed by word character or hyphen.
			. '('                                // 3: Unroll the loop: Inside the opening shortcode tag.
			.     '[^\]\/]*'                   // Not a closing bracket or forward slash.
			.     '(?:'
			.         '\/(?!\])'               // A forward slash not followed by a closing bracket.
			.         '[^\]\/]*'               // Not a closing bracket or forward slash.
			.     ')*?'
			. ')'
			. '(?:'
			.     '(\/)'                        // 4: Self closing tag...
			.     '\]'                          // ...and closing bracket.
			. '|'
			.     '\]'                          // Closing bracket.
			.     '(?:'
			.         '('                        // 5: Unroll the loop: Optionally, anything between the opening and closing shortcode tags.
			.             '[^\[]*+'             // Not an opening bracket.
			.             '(?:'
			.                 '\[(?!\/\2\])' // An opening bracket not followed by the closing shortcode tag.
			.                 '[^\[]*+'         // Not an opening bracket.
			.             ')*+'
			.         ')'
			.         '\[\/\2\]'             // Closing shortcode tag.
			.     ')?'
			. ')'
			. '(\]?)';                          // 6: Optional second closing bracket for escaping shortcodes: [[tag]].
	}
}

/*
 * ---------------------------------------------------------------------------
 * Task 25. What an uninstall can see, and what it is allowed to change.
 * ---------------------------------------------------------------------------
 *
 * Four things are modelled here that nothing above needed, because uninstall.php
 * is the one file in this plugin whose whole job is deletion:
 *
 * - `delete_option()`, which no other class calls.
 * - Multisite: is_multisite(), get_sites(), switch_to_blog(),
 *   restore_current_blog(), get_current_blog_id() and wp_is_large_network().
 *   Every site gets its own options, transients and raw rows, so "this option
 *   is gone from site 7 and still there on site 9" is a thing a case can say.
 * - `$wpdb`, far enough to run one statement shape and no further.
 * - `db_options`, the raw `wp_options` rows **as SQL sees them**, kept
 *   deliberately separate from the `options` and `transients` stores above.
 *
 * That separation is the point rather than an accident, and both halves of it
 * are true of real sites. A transient reached through the options API may or
 * may not be a row in `wp_options`: on a site with a persistent object cache it
 * lives in Redis or Memcached, where no `DELETE` can reach it — which is the
 * warning Store_Repository::CACHE_PREFIX and Geocoder::CACHE_PREFIX both carry.
 * So a case seeds `transients` to describe the object-cache site and
 * `db_options` to describe the row the same transient occupies on a site
 * without one, and the uninstaller has to be right on both.
 *
 * What is NOT modelled, and what that costs:
 *
 * - MySQL, beyond `DELETE FROM <table> WHERE option_name LIKE '…' [OR …]`.
 *   Anything else throws rather than silently doing nothing, because a
 *   statement this file cannot read is a statement no case is checking.
 * - Autoloading, alloptions, and the difference between a delete that found a
 *   row and one that did not: delete_option() here always answers true.
 * - Site *meta*, archived/deleted/spam sites, and networks. get_sites() hands
 *   back the ids it was given, in order, honouring `number` and `offset` only.
 */

if ( ! function_exists( 'slosm_stub_site_state' ) ) {
	/**
	 * Everything that belongs to one site of a network rather than to the process.
	 *
	 * Options, transients, the raw rows, and the content: posts, their meta,
	 * their terms and the taxonomies' term rows. A network is several sites
	 * with several databases' worth of these, and "site 7 asked for its
	 * locations to go and site 9 did not" is a sentence a stub with one set
	 * cannot say.
	 *
	 * Registered post types and taxonomies are deliberately NOT here. They are
	 * per *process* on a real install too — register_post_type() writes a
	 * global array, and switching sites does not re-register anything.
	 *
	 * @return array
	 */
	function slosm_stub_site_state(): array {
		$stub = $GLOBALS['slosm_stub'];

		return array(
			'options'      => $stub['options'],
			'transients'   => $stub['transients'],
			'db_options'   => $stub['db_options'],
			'posts'        => $stub['posts'],
			'posts_by_id'  => $stub['posts_by_id'],
			'post_meta'    => $stub['post_meta'],
			'object_terms' => $stub['object_terms'],
			'terms'        => $stub['terms'],
		);
	}
}

if ( ! function_exists( 'slosm_stub_load_site' ) ) {
	/**
	 * Makes one site's stored state the current one.
	 *
	 * @param array $state A slosm_stub_site_state() array, or an empty array for a site nothing staged.
	 * @return void
	 */
	function slosm_stub_load_site( array $state ): void {
		foreach ( array_keys( slosm_stub_site_state() ) as $key ) {
			$GLOBALS['slosm_stub'][ $key ] = $state[ $key ] ?? array();
		}
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Deletes a stubbed option, and the raw row it would occupy.
	 *
	 * Both stores, because an option is one row: code that deleted the value
	 * the options API can see while leaving the row a `DELETE … LIKE` would
	 * match would be indistinguishable from correct code in a stub that kept
	 * only one of them.
	 *
	 * Always true, unlike core, which answers false when there was no row. No
	 * caller in this plugin reads the return, and a stub that modelled the
	 * false would invite a case to assert on a value production code ignores.
	 *
	 * @param string $key Option name.
	 * @return bool
	 */
	function delete_option( $key ) {
		$GLOBALS['slosm_stub']['options_deleted'][] = $key;
		$GLOBALS['slosm_stub']['option_log'][]      = 'delete:' . $key;

		unset( $GLOBALS['slosm_stub']['options'][ $key ] );
		unset( $GLOBALS['slosm_stub']['db_options'][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	/**
	 * Whether this stubbed site is part of a network.
	 *
	 * @return bool
	 */
	function is_multisite() {
		return (bool) $GLOBALS['slosm_stub']['is_multisite'];
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	/**
	 * The site the process is currently on.
	 *
	 * @return int
	 */
	function get_current_blog_id() {
		return (int) $GLOBALS['slosm_stub']['current_blog_id'];
	}
}

if ( ! function_exists( 'wp_is_large_network' ) ) {
	/**
	 * Whether the network is large enough that core stops counting.
	 *
	 * The default argument is 'sites', which is core's own default in
	 * wp-includes/ms-functions.php line 2707 of WordPress 6.9.1 — worth
	 * copying rather than guessing, because a caller relying on the default
	 * and a stub defaulting to 'users' would agree on every answer and
	 * disagree about which question was asked.
	 *
	 * @param string   $using      'sites' or 'users'.
	 * @param int|null $network_id Ignored here.
	 * @return bool
	 */
	function wp_is_large_network( $using = 'sites', $network_id = null ) {
		$GLOBALS['slosm_stub']['large_network_checks'][] = $using;

		return (bool) $GLOBALS['slosm_stub']['large_network'];
	}
}

if ( ! function_exists( 'get_sites' ) ) {
	/**
	 * The site ids this network has, paged the way WP_Site_Query pages them.
	 *
	 * `number` defaults to 100 because core's does — wp-includes/
	 * class-wp-site-query.php line 194 of WordPress 6.9.1. A stub that handed
	 * back everything when no number was asked for would make an unpaged
	 * uninstaller look identical to a paged one.
	 *
	 * @param array $args Query arguments; only 'fields', 'number' and 'offset' are honoured.
	 * @return array Site ids when 'fields' is 'ids', otherwise objects carrying blog_id.
	 */
	function get_sites( $args = array() ) {
		$GLOBALS['slosm_stub']['site_queries'][] = $args;

		$ids    = array_values( $GLOBALS['slosm_stub']['sites'] );
		$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
		$number = isset( $args['number'] ) ? (int) $args['number'] : 100;
		$slice  = 0 < $number ? array_slice( $ids, $offset, $number ) : array_slice( $ids, $offset );

		if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
			return array_map( 'intval', $slice );
		}

		return array_map(
			static function ( $id ) {
				return (object) array( 'blog_id' => (string) (int) $id );
			},
			$slice
		);
	}
}

if ( ! function_exists( 'switch_to_blog' ) ) {
	/**
	 * Moves the stubbed process to another site of the network.
	 *
	 * Each site keeps its own options, transients and raw rows, and the three
	 * stores every other stub in this file reads are swapped for that site's.
	 * `$wpdb->options` follows, because the stub table names are computed from
	 * the current site rather than stored — see Slosm_Stub_Wpdb.
	 *
	 * What core also does and this does not: fire switch_blog, move the object
	 * cache's blog prefix, and refuse nothing. Nothing here needs any of it.
	 *
	 * @param int  $new_blog_id Site to switch to.
	 * @param null $deprecated  Core's ignored second argument.
	 * @return bool
	 */
	function switch_to_blog( $new_blog_id, $deprecated = null ) {
		$stub    = &$GLOBALS['slosm_stub'];
		$current = (int) $stub['current_blog_id'];
		$target  = (int) $new_blog_id;

		$stub['blogs'][ $current ] = slosm_stub_site_state();

		$stub['blog_stack'][]    = $current;
		$stub['blog_switches'][] = $target;
		$stub['current_blog_id'] = $target;

		slosm_stub_load_site( $stub['blogs'][ $target ] ?? array() );

		return true;
	}
}

if ( ! function_exists( 'restore_current_blog' ) ) {
	/**
	 * Goes back to the site the last switch_to_blog() came from.
	 *
	 * False on an empty stack, which is core's answer too: a restore without a
	 * switch is a bug in the caller, and answering true would hide it.
	 *
	 * @return bool
	 */
	function restore_current_blog() {
		$stub = &$GLOBALS['slosm_stub'];

		$stub['blog_restores']++;

		if ( empty( $stub['blog_stack'] ) ) {
			return false;
		}

		$current = (int) $stub['current_blog_id'];

		$stub['blogs'][ $current ] = slosm_stub_site_state();

		$target = (int) array_pop( $stub['blog_stack'] );

		$stub['current_blog_id'] = $target;

		slosm_stub_load_site( $stub['blogs'][ $target ] ?? array() );

		return true;
	}
}

if ( ! class_exists( 'Slosm_Stub_Wpdb' ) ) {
	/**
	 * Enough of wpdb to run one statement shape, and to be wrong about the rest loudly.
	 *
	 * The table names are computed rather than stored, through __get(), so the
	 * object needs no resetting between cases and follows switch_to_blog()
	 * without being told. Core reaches the same place by a different route:
	 * wpdb::set_blog_id() reassigns every name in wpdb::$tables — which
	 * includes 'options' — from the new blog prefix, at class-wpdb.php lines
	 * 1049-1068 and 291-302 of WordPress 6.9.1.
	 */
	class Slosm_Stub_Wpdb {

		/**
		 * The base prefix, as a real install has it.
		 *
		 * @var string
		 */
		public $prefix = 'wp_';

		/**
		 * The tables whose names this stub will answer for.
		 *
		 * Only 'options' has rows. The others exist so that a statement aimed
		 * at one of them is a name a case can see rather than an undefined
		 * property notice — a mutation that deletes locations has to be
		 * visible here, not fatal.
		 *
		 * @var string[]
		 */
		private const TABLES = array(
			'options',
			'posts',
			'postmeta',
			'terms',
			'term_taxonomy',
			'term_relationships',
			'termmeta',
			'comments',
			'commentmeta',
			'users',
			'usermeta',
		);

		/**
		 * The name of one table on the site the process is currently on.
		 *
		 * @param string $name Table property.
		 * @return string
		 * @throws RuntimeException When the property is not a table this stub knows.
		 */
		public function __get( $name ) {
			if ( ! in_array( $name, self::TABLES, true ) ) {
				throw new RuntimeException( 'the stub $wpdb has no $' . $name );
			}

			$blog = (int) $GLOBALS['slosm_stub']['current_blog_id'];

			// Core's get_blog_prefix(): the main site keeps the base prefix,
			// every other site carries its id in the middle.
			$prefix = 1 === $blog ? $this->prefix : $this->prefix . $blog . '_';

			if ( in_array( $name, array( 'users', 'usermeta' ), true ) ) {
				$prefix = $this->prefix; // Global tables, shared by the network.
			}

			return $prefix . $name;
		}

		/**
		 * Escapes the wildcards out of a string, exactly as core does.
		 *
		 * `addcslashes( $text, '_%\\' )`, verbatim from class-wpdb.php line
		 * 1792 of WordPress 6.9.1. Written out rather than described, because
		 * the whole question a LIKE sweep raises is which underscores are
		 * wildcards.
		 *
		 * @param string $text Raw text.
		 * @return string
		 */
		public function esc_like( $text ) {
			return addcslashes( $text, '_%\\' );
		}

		/**
		 * Quotes arguments into a statement. %s and %d only.
		 *
		 * A count mismatch throws. Core answers a _doing_it_wrong() and runs
		 * nothing, and either way the statement never reaches the database —
		 * but a stub that quietly filled a missing argument with an empty
		 * string would turn a broken sweep into a sweep that deletes
		 * everything.
		 *
		 * @param string $query   Statement with placeholders.
		 * @param mixed  ...$args Values.
		 * @return string
		 * @throws RuntimeException When the number of placeholders and arguments differ.
		 */
		public function prepare( $query, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0]; // Core's array form.
			}

			$wanted = preg_match_all( '/%[sd]/', $query );

			if ( $wanted !== count( $args ) ) {
				throw new RuntimeException(
					'prepare() was given ' . count( $args ) . ' arguments for ' . $wanted . ' placeholders: ' . $query
				);
			}

			$index = 0;

			return (string) preg_replace_callback(
				'/%[sd]/',
				static function ( $match ) use ( &$index, $args ) {
					$value = $args[ $index ];
					$index++;

					if ( '%d' === $match[0] ) {
						return (string) (int) $value;
					}

					// What mysqli_real_escape_string() does to the two
					// characters that can end a quoted literal.
					return "'" . addcslashes( (string) $value, "\\'" ) . "'";
				},
				$query
			);
		}

		/**
		 * Runs one statement against the modelled options table.
		 *
		 * The only shape understood is
		 * `DELETE FROM <table> WHERE option_name LIKE '…' [OR option_name LIKE '…']`.
		 * Anything else throws, so that a statement no case is reading cannot
		 * pass for one that is.
		 *
		 * A statement naming a table other than the current site's options
		 * table is recorded and deletes nothing, because only `db_options` has
		 * rows here. What that buys is that a sweep aimed at wp_posts shows up
		 * in `db_queries` instead of being invisible.
		 *
		 * @param string $sql Statement.
		 * @return int Rows deleted.
		 * @throws RuntimeException When the statement is not one this stub reads.
		 */
		public function query( $sql ) {
			$sql = (string) $sql;

			$GLOBALS['slosm_stub']['db_queries'][] = $sql;

			if ( ! preg_match( '/^\s*DELETE\s+FROM\s+(\S+)\s+WHERE\s+(.+?)\s*$/is', $sql, $statement ) ) {
				throw new RuntimeException( 'the stub database cannot read: ' . $sql );
			}

			$table = $statement[1];
			$where = $statement[2];

			if ( ! preg_match_all( "/option_name\\s+LIKE\\s+'((?:[^'\\\\]|\\\\.)*)'/i", $where, $found ) ) {
				throw new RuntimeException( 'the stub database cannot read the condition: ' . $sql );
			}

			if ( $table !== $this->options ) {
				return 0;
			}

			$patterns = array();

			foreach ( $found[1] as $literal ) {
				// Reverse what prepare() did, leaving the LIKE pattern itself,
				// escapes and wildcards intact.
				$patterns[] = self::like_to_regex( stripslashes( $literal ) );
			}

			$deleted = 0;

			foreach ( array_keys( $GLOBALS['slosm_stub']['db_options'] ) as $name ) {
				foreach ( $patterns as $pattern ) {
					if ( preg_match( $pattern, (string) $name ) ) {
						unset( $GLOBALS['slosm_stub']['db_options'][ $name ] );
						$deleted++;
						break;
					}
				}
			}

			return $deleted;
		}

		/**
		 * Turns a MySQL LIKE pattern into a regular expression.
		 *
		 * `%` is any run of characters, a bare `_` is exactly one, and a
		 * backslash makes the next character literal — which is the whole
		 * reason esc_like() exists and the whole reason a sweep for
		 * `_transient_slosm…` has to escape its own underscores.
		 *
		 * @param string $pattern LIKE pattern, with SQL quoting already reversed.
		 * @return string
		 */
		private static function like_to_regex( string $pattern ): string {
			$regex  = '';
			$length = strlen( $pattern );

			for ( $i = 0; $i < $length; $i++ ) {
				$character = $pattern[ $i ];

				if ( '\\' === $character && $i + 1 < $length ) {
					$i++;
					$regex .= preg_quote( $pattern[ $i ], '#' );
					continue;
				}

				if ( '%' === $character ) {
					$regex .= '.*';
					continue;
				}

				if ( '_' === $character ) {
					$regex .= '.';
					continue;
				}

				$regex .= preg_quote( $character, '#' );
			}

			return '#^' . $regex . '$#s';
		}
	}
}

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new Slosm_Stub_Wpdb();
}

/*
 * ---------------------------------------------------------------------------
 * Task 25, second half. Deleting the site owner's content, on request.
 * ---------------------------------------------------------------------------
 *
 * What these four stubs exist to model is not "a post went away". It is the
 * two places where **registration** decides whether a deletion is complete,
 * both of which bite precisely here and nowhere else in this plugin, because
 * uninstall.php is the only file that runs with the plugin unbootstrapped and
 * therefore with its post type and taxonomy not registered:
 *
 * - `get_terms()` answers WP_Error( 'invalid_taxonomy' ) for a taxonomy
 *   nothing has registered — wp-includes/taxonomy.php lines 1347-1348 of
 *   WordPress 6.9.1. An uninstaller that does not register first enumerates
 *   nothing and deletes nothing, silently.
 * - `wp_delete_post()` clears a post's term relationships through
 *   `wp_delete_object_term_relationships( $post_id, get_object_taxonomies(
 *   $post->post_type ) )` — post.php line 3832. `get_object_taxonomies()` on
 *   an unregistered post type is an empty array, so the post goes and its rows
 *   in term_relationships stay.
 *
 * Both are modelled rather than described, so a mutation that moves the
 * registration is a failing case rather than a paragraph nobody re-reads.
 *
 * `wp_delete_term()` is deliberately NOT gated on registration, because core's
 * is not: it reaches the rows through term_exists(), which queries
 * term_taxonomy.taxonomy as a string and never asks whether anything claims
 * it. A stub that refused would invent a trap that is not there.
 *
 * The force flag is modelled too. `wp_delete_post()` trashes rather than
 * deletes when `$force_delete` is false and EMPTY_TRASH_DAYS is set (post.php
 * lines 3793-3795), and a trashed location is still a location.
 */

if ( ! function_exists( 'slosm_stub_is_registered' ) ) {
	/**
	 * Whether a post type or taxonomy has been registered in this process.
	 *
	 * @param string $kind 'post_types' or 'taxonomies'.
	 * @param string $name Name to look for.
	 * @return bool
	 */
	function slosm_stub_is_registered( string $kind, string $name ): bool {
		foreach ( $GLOBALS['slosm_stub'][ $kind ] as $registered ) {
			if ( (string) ( $registered['name'] ?? '' ) === $name ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'get_post_stati' ) ) {
	/**
	 * Every post status this stubbed site has, names only.
	 *
	 * The list core registers, including the two that `'post_status' => 'any'`
	 * leaves out — `trash` and `auto-draft`, both of which carry
	 * exclude_from_search (class-wp-query.php line 2656). A trashed location
	 * is the case that difference is about.
	 *
	 * @param array  $args     Ignored; no case filters these.
	 * @param string $output   Ignored; names only.
	 * @param string $operator Ignored.
	 * @return string[]
	 */
	function get_post_stati( $args = array(), $output = 'names', $operator = 'and' ) {
		return array(
			'publish'    => 'publish',
			'future'     => 'future',
			'draft'      => 'draft',
			'pending'    => 'pending',
			'private'    => 'private',
			'trash'      => 'trash',
			'auto-draft' => 'auto-draft',
			'inherit'    => 'inherit',
		);
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	/**
	 * Deletes a staged post, or trashes it when the delete is not forced.
	 *
	 * Meta goes with a forced delete, because core's does. Term relationships
	 * go only when the post type is registered, because core's do only then;
	 * see the block header.
	 *
	 * @param int  $post_id      Post to delete.
	 * @param bool $force_delete Bypass the trash.
	 * @return array|false The row that went, or false when there was none.
	 */
	function wp_delete_post( $post_id = 0, $force_delete = false ) {
		$id   = (int) $post_id;
		$stub = &$GLOBALS['slosm_stub'];

		$stub['deleted_posts'][] = array(
			'id'    => $id,
			'force' => (bool) $force_delete,
		);

		/*
		 * A post another plugin refuses to let go. Core's own hook for that is
		 * the `pre_delete_post` filter (post.php line 3813): return anything
		 * that is not null and wp_delete_post() hands it straight back without
		 * touching a row. A caller looping until the query comes back empty
		 * would then loop for ever, which is the failure this knob exists to
		 * let a case provoke without hanging the suite.
		 */
		if ( in_array( $id, $stub['undeletable_posts'], true ) ) {
			return false;
		}

		$found = null;
		$index = null;

		foreach ( $stub['posts'] as $key => $row ) {
			if ( (int) ( $row['ID'] ?? 0 ) === $id ) {
				$found = $row;
				$index = $key;
				break;
			}
		}

		if ( null === $found ) {
			return false;
		}

		if ( ! $force_delete ) {
			// wp_trash_post(): the row stays, under another status.
			$stub['posts'][ $index ]['post_status'] = 'trash';

			if ( isset( $stub['posts_by_id'][ $id ] ) && is_object( $stub['posts_by_id'][ $id ] ) ) {
				$stub['posts_by_id'][ $id ]->post_status = 'trash';
			}

			return $stub['posts'][ $index ];
		}

		unset( $stub['posts'][ $index ] );
		$stub['posts'] = array_values( $stub['posts'] );

		unset( $stub['posts_by_id'][ $id ] );
		unset( $stub['post_meta'][ $id ] );

		if ( slosm_stub_is_registered( 'post_types', (string) ( $found['post_type'] ?? '' ) ) ) {
			unset( $stub['object_terms'][ $id ] );
		}

		return $found;
	}
}

if ( ! function_exists( 'get_terms' ) ) {
	/**
	 * The staged terms of one taxonomy, or WP_Error when nothing registered it.
	 *
	 * Rows come from the same 'terms' store get_term_by() reads, so a fixture
	 * describes a taxonomy once.
	 *
	 * @param array $args Query arguments; 'taxonomy', 'fields' and 'number' are honoured.
	 * @return array|WP_Error
	 */
	function get_terms( $args = array() ) {
		$GLOBALS['slosm_stub']['term_queries'][] = $args;

		$taxonomy = (string) ( $args['taxonomy'] ?? '' );

		if ( ! slosm_stub_is_registered( 'taxonomies', $taxonomy ) ) {
			return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
		}

		$rows   = array_values( $GLOBALS['slosm_stub']['terms'][ $taxonomy ] ?? array() );
		$number = isset( $args['number'] ) ? (int) $args['number'] : 0;

		if ( 0 < $number ) {
			$rows = array_slice( $rows, 0, $number );
		}

		if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
			return array_map(
				static function ( $row ): int {
					return (int) ( $row['term_id'] ?? 0 );
				},
				$rows
			);
		}

		return array_map(
			static function ( $row ) {
				return (object) $row;
			},
			$rows
		);
	}
}

if ( ! function_exists( 'wp_delete_term' ) ) {
	/**
	 * Deletes a staged term, and the object relationships that named it.
	 *
	 * True on success and false for a term that is not there, which is what
	 * core answers and what lets a caller's loop know it is making progress.
	 *
	 * @param int    $term     Term id.
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	function wp_delete_term( $term, $taxonomy ) {
		$id   = (int) $term;
		$stub = &$GLOBALS['slosm_stub'];

		$stub['deleted_terms'][] = array(
			'term_id'  => $id,
			'taxonomy' => (string) $taxonomy,
		);

		$rows  = $stub['terms'][ $taxonomy ] ?? array();
		$found = false;

		foreach ( $rows as $key => $row ) {
			if ( (int) ( $row['term_id'] ?? 0 ) === $id ) {
				unset( $rows[ $key ] );
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return false;
		}

		$stub['terms'][ $taxonomy ] = array_values( $rows );

		return true;
	}
}

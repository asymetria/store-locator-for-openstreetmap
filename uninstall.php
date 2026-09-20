<?php
/**
 * What deleting this plugin takes with it.
 *
 * WHAT IT REMOVES ALWAYS
 * ======================
 * Five options and the plugin's own transients. Nothing else, unless the site
 * has ticked the box described further down.
 *
 *   slosm_settings                        the settings screen's one option
 *   slosm_cache_generation                the map cache's invalidation counter
 *   slosm_geocode_generation              the geocode cache's counter
 *   slosm_geocode_last_request_nominatim  one service's courtesy-limit clock
 *   slosm_geocode_last_request_photon     the other service's
 *
 * Both counters, never one. Each falls back to zero through an identical
 * absint( get_option( …, 0 ) ) — Store_Repository::generation() and
 * Geocoder::generation() — so a reinstall starts life computing keys with `_g0`
 * in them. On a site with Redis or Memcached in front of it the payloads
 * written under the *previous* `_g0` are still there, because no statement in
 * this file can reach them: they are not rows. Dropping one counter while its
 * payloads survive therefore makes stale entries reachable again — up to a day
 * of wrong pins from the map cache, up to thirty days of wrong coordinates from
 * the geocode cache, on a site that has just reinstalled the plugin and has
 * every reason to think it is starting fresh.
 *
 * WHAT IT REMOVES ONLY IF ASKED
 * =============================
 * **The locations**, their meta and their categories — the `slosm_store` posts
 * and the `slosm_store_category` terms. They are posts of a public post type
 * with the addresses somebody typed on them, so the default is to keep them:
 * deleting a plugin is a decision about a plugin, and try it, remove it, put
 * it back next week is an ordinary week on a real site.
 *
 * The site says otherwise through one checkbox, `remove_data` in
 * Settings::defaults(), off by default and labelled "Delete every location,
 * category and setting when this plugin is deleted". Silence means keep.
 *
 * That flag lives inside `slosm_settings`, which this file also deletes, and
 * the order those two things happen in is the whole correctness of the
 * feature. See the note above the read in slosm_uninstall_site().
 *
 * What it still never touches: any other post type, any other taxonomy, and
 * anybody else's options or transients. Two cases pin that with a page and a
 * term of another taxonomy in the fixture, in both branches.
 *
 * ONE SITE, OR THE WHOLE NETWORK
 * ==============================
 * delete_option() acts on the site it runs on and no other, so on a network an
 * uninstaller that only calls it leaves every other site's settings behind for
 * good. This file iterates instead, in pages, and skips the network the moment
 * WordPress itself says the network is too big to walk — wp_is_large_network(),
 * which is 'sites' by default and answers true above ten thousand of them, and
 * which a network admin can move with a filter.
 *
 * The reasoning, since either answer is defensible:
 *
 * - What is left behind by not iterating never expires. An option is not a
 *   cache; `slosm_settings` on a site nobody cleaned is there in five years.
 * - What iterating costs is bounded and visible: one page of ids per hundred
 *   sites, then five deletes and five statements per site. On a fifty-site
 *   agency network that is nothing. On a ten-thousand-site network it is a
 *   request that will not finish — and an uninstall that dies half way has no
 *   second chance, because WordPress has already removed the files by then.
 * - The large-network branch is therefore not "do nothing". It degrades to
 *   exactly what a single-site uninstaller would have done: clean the site the
 *   request is on. On a network that size, a scheduled sweep by the network
 *   admin is the right tool and this file is not it.
 *
 * WHY THE NAMES ARE SPELLED OUT RATHER THAN READ OFF THE CLASSES
 * =============================================================
 * This file loads no part of the plugin. WordPress includes it with the plugin
 * inactive, from inside uninstall_plugin(), with nothing of the plugin
 * bootstrapped, and pulling four class files in to read five strings would make
 * an uninstall depend on code whose own headers assume a booted plugin.
 *
 * The cost of literals is that a rename could pass unnoticed, and that cost is
 * paid in tests/test-uninstall.php instead: every name below is asserted there
 * against the constant that publishes it — Settings::OPTION,
 * Store_Repository::GENERATION_OPTION, Geocoder::GENERATION_OPTION,
 * Geocoder::last_request_option(), and the five prefixes — and one case
 * re-reads every OPTION and PREFIX constant in the source and fails if a new
 * one has appeared that nobody has classified.
 *
 * WHAT THE SWEEP CAN AND CANNOT REACH
 * ===================================
 * A transient is two rows in wp_options — `_transient_<key>` and
 * `_transient_timeout_<key>` — only on a site with no persistent object cache.
 * Everywhere else it lives in Redis or Memcached under keys no SQL can see, and
 * the sweep below finds nothing at all. That is not a bug to fix here and it is
 * why deleting the two generation counters is the part that matters: it is the
 * only reach this code has on those sites.
 *
 * One more gap, stated rather than hidden: a site filtering `slosm_cache_key`
 * (Task 20) can put the map payload under a key of its own choosing. If that
 * key does not start with the prefix below, this sweep will not match it, and
 * the payload expires on its own ttl instead.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'slosm_uninstall_site' ) ) {
	/**
	 * Removes this plugin's stored data from the site the process is on.
	 *
	 * @return void
	 */
	function slosm_uninstall_site(): void {
		global $wpdb;

		/*
		 * READ THE FLAG FIRST. THIS LINE'S POSITION IS THE FEATURE.
		 *
		 * The switch that says whether the locations go lives *inside*
		 * slosm_settings, and slosm_settings is four lines below on the list
		 * of options this function deletes. Move this read under that loop and
		 * nothing breaks, nothing warns, and no end state changes except the
		 * one nobody looks at: get_option() answers the default, the flag is
		 * false for every site on earth, and the checkbox silently stops
		 * working. Both orders leave the same wreckage, which is why the
		 * ordering has a case of its own rather than being trusted to the
		 * cases about either branch.
		 *
		 * Read once, kept in a variable, and never read again — see
		 * slosm_uninstall_content(), which takes the answer as an argument
		 * rather than going back for it.
		 *
		 * `! empty()` rather than a cast or an isset(): the option is whatever
		 * is in the database, which on a site restored from a backup taken
		 * before the settings screen existed may be a string, an object or a
		 * serialised fragment of something else. Everything that is not an
		 * array with a true-ish flag in it means keep the content, because
		 * that is the answer that cannot destroy anything.
		 */
		$settings    = get_option( 'slosm_settings', array() );
		$remove_data = is_array( $settings ) && ! empty( $settings['remove_data'] );

		$options = array(
			'slosm_settings',
			'slosm_cache_generation',
			'slosm_geocode_generation',
			'slosm_geocode_last_request_nominatim',
			'slosm_geocode_last_request_photon',
		);

		foreach ( $options as $option ) {
			delete_option( $option );
		}

		/*
		 * Every transient prefix this plugin writes under, and what each one
		 * holds:
		 *
		 *   slosm_stores_lean       the map payload, per language and generation
		 *   slosm_geo               geocoded addresses and suggestion lists
		 *   slosm_location_notices_ what to tell an editor after a save
		 *   slosm_geocode_failed_   an address already known to fail
		 *   slosm_bulk_geocode_     the last bulk run's report
		 *
		 * The second is a prefix of the fourth, so they overlap; that costs one
		 * statement and is clearer than a list with a hole in it.
		 */
		$prefixes = array(
			'slosm_stores_lean',
			'slosm_geo',
			'slosm_location_notices_',
			'slosm_geocode_failed_',
			'slosm_bulk_geocode_',
		);

		foreach ( $prefixes as $prefix ) {
			/*
			 * esc_like() over the whole name, `_transient_` included, and only
			 * then the wildcard. An unescaped underscore in a LIKE pattern is
			 * any single character, so a pattern built the other way round
			 * would also match rows called `Xtransient_slosm…` — rows that are
			 * not transients and are not this plugin's.
			 *
			 * esc_like() before prepare(), never after: reversing the two is
			 * the mistake wpdb::esc_like()'s own docblock warns about.
			 */
			$like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
			$time = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- there is no options API for "every option whose name starts with", and an uninstall has nothing left to cache for.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$like,
					$time
				)
			);
		}

		if ( $remove_data ) {
			slosm_uninstall_content();
		}
	}
}

if ( ! function_exists( 'slosm_uninstall_content' ) ) {
	/**
	 * Deletes this site's locations and their categories. Only ever called when asked.
	 *
	 * WHY THIS REGISTERS THE POST TYPE AND THE TAXONOMY FIRST
	 * ======================================================
	 * Because nothing else has. WordPress includes this file with the plugin
	 * inactive, so `slosm_store` and `slosm_store_category` are names in the
	 * database that nothing in the process claims — and two pieces of core
	 * quietly do less when that is true:
	 *
	 * - `get_terms()` answers WP_Error( 'invalid_taxonomy' ) outright, at
	 *   wp-includes/taxonomy.php lines 1347-1348. Without the registration
	 *   there is no list of terms to delete, no error anybody sees, and the
	 *   categories simply stay.
	 * - `wp_delete_post()` clears a post's term relationships with
	 *   `wp_delete_object_term_relationships( $post_id, get_object_taxonomies(
	 *   $post->post_type ) )`, at post.php line 3832.
	 *   `get_object_taxonomies()` on an unregistered type is an empty array, so
	 *   the post goes and its rows in wp_term_relationships stay behind,
	 *   pointing at nothing.
	 *
	 * Both are registered privately and minimally. Nothing here needs a UI, a
	 * rewrite rule or a REST route; what it needs is for core to know the
	 * names exist. Registering a name a second time replaces the object rather
	 * than complaining, which is why calling this once per site of a network
	 * is safe.
	 *
	 * WHY wp_delete_post() RATHER THAN A DELETE STATEMENT
	 * ===================================================
	 * A statement would be one query per thousand locations instead of dozens
	 * per location, and it would be wrong in two ways that only show up later.
	 * It leaves the post meta and the term relationships orphaned — rows in
	 * wp_postmeta keyed to ids nothing points at any more — and it tells
	 * nobody. Deleting a post is an event other plugins listen for: search
	 * indexes, object caches, multilingual tables that hold a translation of
	 * every post id. None of those hooks are this plugin's — this plugin is
	 * not loaded — and all of them belong to software that is still installed
	 * and will go on believing these locations exist.
	 *
	 * `true` for the second argument, because the trash is not what the
	 * checkbox offered. A site owner who ticked "delete every location" and
	 * found them all in the trash would be right to call that a bug.
	 *
	 * WHY IT PAGES, AND HOW IT KNOWS WHEN TO STOP
	 * ===========================================
	 * A site with thousands of locations must not be asked for all of them in
	 * one array, so this takes a hundred ids at a time. Deleting while paging
	 * makes an offset meaningless — the rows move under it — so there is no
	 * offset: each pass asks for the first hundred again, and the ones deleted
	 * last time are not in it.
	 *
	 * That terminates only while something is actually being deleted, and
	 * `wp_delete_post()` can answer false for reasons outside this file: the
	 * `pre_delete_post` filter (post.php line 3813) lets any plugin on the
	 * site veto a deletion. So the loop also stops when a page comes back
	 * identical to the page before it, which is the signature of no progress.
	 * Without that, a single vetoed location is an uninstall that never ends —
	 * on a request that cannot be retried, because the plugin's files are
	 * already gone.
	 *
	 * Every status a site has registered, rather than `'any'`: `'any'` leaves
	 * out the statuses flagged exclude_from_search (class-wp-query.php line
	 * 2656), which are `trash` and `auto-draft`. A location in the trash is
	 * still a location, and leaving it would be the worst possible answer —
	 * the owner asked for the addresses to be gone and some of them would not
	 * be.
	 *
	 * @return void
	 */
	function slosm_uninstall_content(): void {
		register_post_type( 'slosm_store', array( 'public' => false ) );
		register_taxonomy( 'slosm_store_category', 'slosm_store', array( 'public' => false ) );

		$batch    = 100;
		$previous = null;

		do {
			$ids = get_posts(
				array(
					'post_type'        => 'slosm_store',
					'post_status'      => array_keys( get_post_stati() ),
					'fields'           => 'ids',
					'numberposts'      => $batch,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					/*
					 * Reported by Plugin Check through a VIP sniff, and kept
					 * on purpose. `suppress_filters` is true by default in
					 * get_posts(); stating it is the point, because what it
					 * buys here is the opposite of what the sniff is guarding
					 * against. This runs during uninstall, and its job is to
					 * find every one of this plugin's own posts so that they
					 * can be deleted. A multilingual plugin's query filter —
					 * which is what suppression turns off — would hide the
					 * ones not in the current language, and this would then
					 * leave rows behind in a table nobody is coming back to.
					 * Deleting less than everything is the failure mode here.
					 */
					// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- uninstall must see every post of its own, in every language.
					'suppress_filters' => true,
				)
			);

			$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();

			if ( $ids === $previous ) {
				break; // Nothing moved last time round; it will not move now.
			}

			$previous = $ids;

			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
			}
		} while ( array() !== $ids );

		$previous = null;

		do {
			$terms = get_terms(
				array(
					'taxonomy'   => 'slosm_store_category',
					'hide_empty' => false,
					'fields'     => 'ids',
					'number'     => $batch,
				)
			);

			$terms = is_array( $terms ) ? array_map( 'intval', $terms ) : array();

			if ( $terms === $previous ) {
				break;
			}

			$previous = $terms;

			foreach ( $terms as $term ) {
				wp_delete_term( $term, 'slosm_store_category' );
			}
		} while ( array() !== $terms );
	}
}

if ( ! function_exists( 'slosm_uninstall' ) ) {
	/**
	 * Cleans the site the uninstall runs on, and the rest of the network when there is one.
	 *
	 * @return void
	 */
	function slosm_uninstall(): void {
		slosm_uninstall_site();

		if ( ! is_multisite() ) {
			return;
		}

		// Reading the network's size is cheap; walking a network this size is
		// not. See the header for why this degrades to the single-site answer
		// rather than to nothing.
		if ( wp_is_large_network( 'sites' ) ) {
			return;
		}

		$current = get_current_blog_id();
		$batch   = 100;
		$offset  = 0;

		do {
			$ids = get_sites(
				array(
					'fields'  => 'ids',
					'number'  => $batch,
					'offset'  => $offset,
					'orderby' => 'id',
					'order'   => 'ASC',
				)
			);

			$ids = is_array( $ids ) ? $ids : array();

			foreach ( $ids as $id ) {
				$id = (int) $id;

				if ( $id === $current ) {
					continue; // Already done, above, and before any switching.
				}

				switch_to_blog( $id );
				slosm_uninstall_site();
				restore_current_blog();
			}

			$offset += $batch;
		} while ( count( $ids ) === $batch );
	}
}

slosm_uninstall();

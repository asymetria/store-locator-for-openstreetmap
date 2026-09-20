<?php
/**
 * Pins the map payload: how a row becomes a Store, and what the cache promises.
 *
 * Two contracts live here and they fail in different ways.
 *
 * The mapping is the silent one. Store::from_array() ignores a key it does not
 * recognise, so one mistyped name in the repository's table is one field that is
 * blank on every location, on every site, with no warning anywhere. Nothing in
 * the lean payload would show it either — ten of the seventeen fields are not in
 * the payload at all. So the mapping cases work against literal meta keys
 * written out in the fixture below, never against the constant under test: a
 * test that stages '_slosm_' . $field out of the same array it is checking is a
 * test that agrees with any typo.
 *
 * The cache is the loud one, and the trap is the opposite. A cache test that
 * only calls find_all() twice and compares the results passes perfectly with the
 * cache deleted, because a rebuild returns the same rows. So every case here
 * counts loader invocations.
 *
 * One request, one repository
 * ---------------------------
 * The repository memoises the payload for the life of the instance, so two
 * find_all() calls on one object prove nothing about the transient — the second
 * never reaches it. Every case about the transient therefore builds a second
 * repository over the same loader, which is what the next request does. Cases
 * about the memo itself are the ones that reuse an instance, and they say so.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-store.php';
require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
require_once dirname( __DIR__ ) . '/includes/class-store-repository.php';

use Asymetria\StoreLocator\Store;
use Asymetria\StoreLocator\Store_Repository;

if ( ! function_exists( 'pll_current_language' ) ) {
	/**
	 * Stands in for Polylang's own function, which the repository asks for by name.
	 *
	 * Defined here rather than in bootstrap.php because it is not WordPress, and
	 * defined at all because function_exists() is the only way the repository can
	 * ask whether Polylang is installed — a branch no test could otherwise reach.
	 *
	 * It reads stub state, so it is inert unless a case stages a language, and
	 * it() clears that between cases. Every other case in the suite therefore
	 * takes this branch and falls straight through it, exactly as a site without
	 * Polylang would if Polylang were somehow loaded and switched off.
	 *
	 * @param string $field Unused; Polylang's own signature takes one.
	 * @return string|false
	 */
	function pll_current_language( $field = 'slug' ) {
		return $GLOBALS['slosm_stub']['options']['slosm_test_polylang'] ?? false;
	}
}

if ( ! function_exists( 'slosm_test_wpml_language' ) ) {
	/**
	 * Answers the wpml_current_language filter with whatever a case staged.
	 *
	 * @param mixed $language Value being filtered.
	 * @return mixed
	 */
	function slosm_test_wpml_language( $language ) {
		return $GLOBALS['slosm_stub']['options']['slosm_test_wpml'] ?? $language;
	}
}

if ( ! function_exists( 'slosm_test_language' ) ) {
	/**
	 * Stages the language WPML would report for this request.
	 *
	 * The filter has to be registered by the case as well; registering it here
	 * would hide which cases are multilingual.
	 *
	 * @param string $language Language code.
	 * @return void
	 */
	function slosm_test_language( string $language ): void {
		$GLOBALS['slosm_stub']['options']['slosm_test_wpml'] = $language;
	}
}

if ( ! function_exists( 'slosm_test_row' ) ) {
	/**
	 * One row shaped the way get_posts() hands them over.
	 *
	 * Touches no stub state, so it is safe to call from anywhere, including
	 * group scope.
	 *
	 * @param int    $id      Post id.
	 * @param string $title   Post title.
	 * @param string $content Post content.
	 * @return object
	 */
	function slosm_test_row( int $id, string $title = '', string $content = '' ): object {
		return (object) array(
			'ID'           => $id,
			'post_title'   => $title,
			'post_content' => $content,
		);
	}
}

if ( ! function_exists( 'slosm_test_seed' ) ) {
	/**
	 * Stages one location's meta and categories.
	 *
	 * Meta keys are passed in by the caller, spelled out in full, so the
	 * repository's own table is never the source of the fixture it is checked
	 * against.
	 *
	 * @param int      $id         Post id.
	 * @param array    $meta       Meta keys to values.
	 * @param string[] $categories Term names.
	 * @return void
	 */
	function slosm_test_seed( int $id, array $meta, array $categories = array() ): void {
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		$GLOBALS['slosm_stub']['object_terms'][ $id ] = $categories;
	}
}

if ( ! function_exists( 'slosm_test_located' ) ) {
	/**
	 * Stages the coordinates a location needs to reach the map payload.
	 *
	 * @param int    $id  Post id.
	 * @param string $lat Latitude as post meta stores it.
	 * @param string $lng Longitude as post meta stores it.
	 * @return void
	 */
	function slosm_test_located( int $id, string $lat, string $lng ): void {
		slosm_test_seed(
			$id,
			array(
				'_slosm_lat' => $lat,
				'_slosm_lng' => $lng,
			)
		);
	}
}

if ( ! function_exists( 'slosm_test_ids' ) ) {
	/**
	 * The ids of a list of locations, for comparing two results in one assertion.
	 *
	 * @param Store[] $stores Locations.
	 * @return int[]
	 */
	function slosm_test_ids( array $stores ): array {
		$ids = array();

		foreach ( $stores as $store ) {
			$ids[] = $store->id;
		}

		return $ids;
	}
}

describe(
	'store repository mapping',
	function () {

		before_each(
			function () {
				// Every meta key written out by hand. See the file header.
				slosm_test_seed(
					7,
					array(
						'_slosm_address'    => 'Nowy Świat 1',
						'_slosm_address2'   => 'lokal 3',
						'_slosm_city'       => 'Warszawa',
						'_slosm_state'      => 'mazowieckie',
						'_slosm_zip'        => '00-001',
						'_slosm_country'    => 'Polska',
						'_slosm_lat'        => '52.2297',
						'_slosm_lng'        => '21.0122',
						'_slosm_lat_locked' => '1',
						'_slosm_phone'      => '+48 22 000 00 00',
						'_slosm_email'      => 'kawa@example.test',
						'_slosm_url'        => 'https://example.test/kawiarnia',
						'_slosm_hours'      => "Mon 9-17\nTue 9-17",
					),
					array( 'Kawiarnia', 'Warszawa' )
				);
			}
		);

		it(
			'reads every field of a location out of the row, the meta and the taxonomy',
			function () {
				$store = ( new Store_Repository() )->to_store(
					slosm_test_row( 7, 'Kawiarnia', 'Palarnia i kawiarnia.' )
				);

				// Compared against a record written out here, not against
				// another call on the same object: a comparison of the class
				// with itself is symmetric under every change to it, so a key
				// that stops being read disappears from both sides at once and
				// the test stays green. This is the assertion that turns a
				// mistyped meta key into a red suite instead of a blank field on
				// a client's site.
				$expected = array(
					'id'          => 7,
					'name'        => 'Kawiarnia',
					'description' => 'Palarnia i kawiarnia.',
					'address'     => 'Nowy Świat 1',
					'address2'    => 'lokal 3',
					'city'        => 'Warszawa',
					'state'       => 'mazowieckie',
					'zip'         => '00-001',
					'country'     => 'Polska',
					'lat'         => 52.2297,
					'lng'         => 21.0122,
					'lat_locked'  => true,
					'phone'       => '+48 22 000 00 00',
					'email'       => 'kawa@example.test',
					'url'         => 'https://example.test/kawiarnia',
					'hours'       => "Mon 9-17\nTue 9-17",
					'categories'  => array( 'Kawiarnia', 'Warszawa' ),
				);
				$full     = $store->to_full_array();

				ksort( $expected );
				ksort( $full );

				assert_same( $expected, $full );
			}
		);

		it(
			'plucks the category names out of the term objects',
			function () {
				$store = ( new Store_Repository() )->to_store( slosm_test_row( 7, 'Kawiarnia' ) );

				// get_the_terms() has no 'fields' argument: it returns WP_Term
				// objects and nothing else. Store drops a non-scalar category
				// rather than casting it — a WP_Term has no __toString() and the
				// cast would give a blank chip with the right count — so a
				// repository that forgets to pluck the names ships every location
				// with no categories and no error anywhere.
				assert_same( array( 'Kawiarnia', 'Warszawa' ), $store->categories );

				$request = $GLOBALS['slosm_stub']['term_requests'][0];

				assert_same( 'slosm_store_category', $request['taxonomy'] );
				assert_same( 7, $request['object_id'] );
			}
		);

		it(
			'reads no category from a location that has none, and no warning either',
			function () {
				$GLOBALS['slosm_stub']['object_terms'][7] = array();

				// get_the_terms() answers false rather than an empty array when a
				// location has no categories. wp_list_pluck() would foreach over
				// that — WP_List_Util casts nothing — which is a PHP 8 warning on
				// a front-end page for every uncategorised location. Nothing else
				// in this suite notices a warning, so this case turns one into a
				// failure for the length of the call.
				set_error_handler(
					static function ( $errno, $message ) {
						throw new RuntimeException( $message );
					},
					E_ALL
				);

				try {
					$store = ( new Store_Repository() )->to_store( slosm_test_row( 7, 'Kawiarnia' ) );
				} finally {
					restore_error_handler();
				}

				assert_same( array(), $store->categories );
			}
		);

		it(
			'has a meta key for every field that is not a post column',
			function () {
				// The four post-column fields are written out here rather than
				// read from the class, so moving a field into that list to
				// excuse a missing meta key fails this case instead of hiding
				// in it.
				$post_fields = array( 'id', 'name', 'description', 'categories' );

				assert_same( $post_fields, Store_Repository::POST_FIELDS );

				$expected = array_values( array_diff( Store::FIELDS, $post_fields ) );
				$mapped   = array_keys( Store_Repository::META_KEYS );

				sort( $expected );
				sort( $mapped );

				assert_same( $expected, $mapped, 'the meta table and Store::FIELDS disagree' );
			}
		);

		it(
			'stores every meta field under its own prefixed key',
			function () {
				// The table is written out literally in the class, not derived
				// from the field name, so that a field can be renamed one day
				// without rewriting every row in wp_postmeta. This case states
				// the layout as it stands today, which is what catches a typo
				// in one of those literals; the day a rename happens, this is
				// the line that has to change in the same commit as the
				// migration, deliberately rather than by accident.
				foreach ( Store_Repository::META_KEYS as $field => $meta_key ) {
					assert_same( '_slosm_' . $field, $meta_key );
				}

				assert_same( 13, count( Store_Repository::META_KEYS ) );
			}
		);

		it(
			'reads no category at all when the taxonomy answers with an error',
			function () {
				$GLOBALS['slosm_stub']['object_terms'][7] = new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );

				$store = ( new Store_Repository() )->to_store( slosm_test_row( 7, 'Kawiarnia' ) );

				// Store itself would have survived a WP_Error — its categories()
				// checks is_array() and hands back an empty list. What the guard
				// prevents is a TypeError raised inside the repository, whose
				// categories_for() declares an array return. The guard is about
				// where the failure lands, not about whether the value could
				// reach a Store.
				assert_same( array(), $store->categories );
			}
		);
	}
);

describe(
	'store repository cache key',
	function () {

		it(
			'names the plugin, the payload version and the language',
			function () {
				add_filter( 'wpml_current_language', 'slosm_test_wpml_language' );
				slosm_test_language( 'pl' );

				$key = ( new Store_Repository() )->cache_key();

				// The prefix is a contract: the uninstaller, the "clear cache"
				// button and Task 8's sweep all find these transients by it. The
				// version is what closes the window in which a plugin update
				// serves payloads written in the old shape. The rest of the name
				// is not pinned.
				assert_same( 'slosm_', substr( $key, 0, 6 ) );
				assert_contains( '_v1_', $key );
				assert_contains( '_pl', $key );
			}
		);

		it(
			'asks Polylang when WPML is not answering',
			function () {
				$GLOBALS['slosm_stub']['options']['slosm_test_polylang'] = 'de';

				assert_contains( '_de', ( new Store_Repository() )->cache_key() );
			}
		);

		it(
			'prefers WPML when both are installed',
			function () {
				add_filter( 'wpml_current_language', 'slosm_test_wpml_language' );
				slosm_test_language( 'pl' );
				$GLOBALS['slosm_stub']['options']['slosm_test_polylang'] = 'de';

				// The plugin doing the filtering is the one whose answer matches
				// the rows the loader just returned.
				$key = ( new Store_Repository() )->cache_key();

				assert_contains( '_pl', $key );
				assert_false( false !== strpos( $key, '_de' ) );
			}
		);

		it(
			'falls back to the site locale, folded into a key WordPress will accept',
			function () {
				update_option( 'locale', 'pt_BR' );

				// A monolingual site has one locale on the front end and so one
				// key. The fold is sanitize_key(): this goes into an option name,
				// and a language code from a filter is whatever a plugin returned.
				assert_contains( '_pt_br', ( new Store_Repository() )->cache_key() );
			}
		);
	}
);

describe(
	'store repository find_all',
	function () {

		it(
			'builds the lean list once and serves the rest from cache',
			function () {
				slosm_test_located( 1, '52.2297', '21.0122' );
				slosm_test_located( 2, '50.0647', '19.9450' );

				$calls  = 0;
				$loader = function () use ( &$calls ) {
					$calls++;

					return array(
						slosm_test_row( 1, 'Warszawa' ),
						slosm_test_row( 2, 'Kraków' ),
					);
				};

				// Two repositories over one loader: the second stands for the next
				// request, which is the only thing the transient can be proved
				// against. One instance would answer from its own memo and prove
				// nothing.
				$first  = ( new Store_Repository( $loader ) )->find_all();
				$second = ( new Store_Repository( $loader ) )->find_all();

				// The invocation count is the whole assertion. Comparing the two
				// results proves nothing on its own: a repository with no cache
				// at all returns the same two locations twice.
				assert_same( 1, $calls, 'the second request went back to the loader' );
				assert_same( array( 1, 2 ), slosm_test_ids( $first ) );
				assert_same( array( 1, 2 ), slosm_test_ids( $second ) );
			}
		);

		it(
			'answers the rest of the request from memory',
			function () {
				slosm_test_located( 1, '52.2297', '21.0122' );

				$calls = 0;
				$repo  = new Store_Repository(
					function () use ( &$calls ) {
						$calls++;

						return array( slosm_test_row( 1, 'Warszawa' ) );
					}
				);

				$repo->find_all();

				// Deleted behind its back, so only the memo can answer. Two
				// shortcodes on one page otherwise each read and unserialize
				// 120 KB for a payload that cannot have changed — and Task 7's
				// find_near() is specified against "the cached list when it is
				// already loaded", which was not true of anything until this.
				delete_transient( $repo->cache_key() );
				$repo->find_all();

				assert_same( 1, $calls, 'the second call in the same request rebuilt' );

				// flush_cache() has to leave this request rebuilding, or the
				// save-then-render sequence it exists to serve shows the editor
				// the data they just changed away from.
				//
				// Honest about what this proves: since the generation went into
				// the key, the memo is keyed to a key the flush has just changed,
				// so this passes with the memo clearing in flush_cache() deleted.
				// It pins the behaviour, not the line. The case in
				// tests/test-cache-invalidation.php that does pin the line is
				// 'clears the memo even when the generation cannot move again'.
				$repo->flush_cache();
				$repo->find_all();

				assert_same( 2, $calls, 'flush_cache left this request answering from before the flush' );
			}
		);

		it(
			'drops locations without coordinates from the map payload',
			function () {
				slosm_test_located( 1, '52.2297', '21.0122' );
				slosm_test_located( 2, 'brak', '21.0122' );   // Not a number.
				slosm_test_located( 3, '', '' );              // Never geocoded.
				slosm_test_located( 4, '0', '0' );            // A geocode that failed and wrote zeros.
				slosm_test_located( 5, '50.0647', '19.9450' );

				$repo = new Store_Repository(
					function () {
						return array(
							slosm_test_row( 1, 'Warszawa' ),
							slosm_test_row( 2, 'Bez wspolrzednych' ),
							slosm_test_row( 3, 'Nowy' ),
							slosm_test_row( 4, 'Zatoka Gwinejska' ),
							slosm_test_row( 5, 'Kraków' ),
						);
					}
				);

				// Not "reaches the payload with lat => null": an unplaceable
				// location cannot be drawn, and one that ships anyway is a row
				// the browser has to filter out again on every render.
				assert_same( array( 1, 5 ), slosm_test_ids( $repo->find_all() ) );
			}
		);

		it(
			'rebuilds after flush_cache',
			function () {
				slosm_test_located( 1, '52.2297', '21.0122' );

				$calls  = 0;
				$loader = function () use ( &$calls ) {
					$calls++;

					return array( slosm_test_row( 1, 'Warszawa' ) );
				};

				$repo = new Store_Repository( $loader );
				$repo->find_all();
				$repo->flush_cache();

				// The transient is gone, not merely stale. get_transient()
				// answers false for a stored false as well as for a miss, so the
				// key itself is what proves a delete happened — and the next
				// request, which has no memo, is what proves it is really gone.
				assert_false( array_key_exists( $repo->cache_key(), $GLOBALS['slosm_stub']['transients'] ) );

				( new Store_Repository( $loader ) )->find_all();

				assert_same( 2, $calls, 'flush_cache left the list cached' );
			}
		);

		it(
			'clears every language, not only the one the request runs in',
			function () {
				add_filter( 'wpml_current_language', 'slosm_test_wpml_language' );
				slosm_test_located( 1, '52.2297', '21.0122' );

				$calls  = 0;
				$loader = function () use ( &$calls ) {
					$calls++;

					return array( slosm_test_row( 1, 'Warszawa' ) );
				};

				slosm_test_language( 'pl' );
				( new Store_Repository( $loader ) )->find_all();

				slosm_test_language( 'en' );
				( new Store_Repository( $loader ) )->find_all();

				// A save happens in one language. Until the generation went into
				// the key, this case pinned the opposite behaviour — a Polish
				// save deleting the Polish key and leaving the English map wrong
				// for a day — so that closing the gap had to be a deliberate
				// change to this case rather than a silent one. This is that
				// change. tests/test-cache-invalidation.php has the same
				// property proved through the hooks.
				slosm_test_language( 'pl' );
				( new Store_Repository( $loader ) )->flush_cache();

				// Nothing was deleted. Both payloads are still sitting in the
				// object cache, unreachable under a generation no key will name
				// again, and they go when their ttl runs out.
				assert_same( 2, count( $GLOBALS['slosm_stub']['transients'] ) );

				slosm_test_language( 'pl' );
				( new Store_Repository( $loader ) )->find_all();

				assert_same( 3, $calls, 'the language the flush ran in was not cleared' );

				slosm_test_language( 'en' );
				( new Store_Repository( $loader ) )->find_all();

				assert_same( 4, $calls, 'a flush in Polish left the English payload readable' );
			}
		);

		it(
			'gives each language its own payload',
			function () {
				add_filter( 'wpml_current_language', 'slosm_test_wpml_language' );
				slosm_test_located( 1, '52.2297', '21.0122' );
				slosm_test_located( 2, '51.1079', '17.0385' );

				$calls = 0;
				$repo  = new Store_Repository(
					function () use ( &$calls ) {
						$calls++;

						// What suppress_filters => false produces: the query has
						// already been narrowed to the current language, so the
						// loader returns one language's locations and not the
						// site's.
						return 'pl' === ( $GLOBALS['slosm_stub']['options']['slosm_test_wpml'] ?? '' )
							? array( slosm_test_row( 1, 'Warszawa' ) )
							: array( slosm_test_row( 2, 'Wrocław' ) );
					}
				);

				slosm_test_language( 'pl' );
				$polish = $repo->find_all();

				slosm_test_language( 'en' );
				$english = $repo->find_all();

				slosm_test_language( 'pl' );
				$polish_again = $repo->find_all();

				// Under one global key the second language would have overwritten
				// the first, and every Polish visitor would then have been served
				// the English map until the transient expired. The third call is
				// the one that proves it did not: it is answered from the Polish
				// payload, which is still there.
				assert_same( array( 1 ), slosm_test_ids( $polish ) );
				assert_same( array( 2 ), slosm_test_ids( $english ) );
				assert_same( array( 1 ), slosm_test_ids( $polish_again ) );
				assert_same( 2, $calls, 'a language rebuilt a payload that was already cached' );
				assert_same( 2, count( $GLOBALS['slosm_stub']['transients'] ) );
			}
		);

		it(
			'does not cache a payload built before the taxonomy exists',
			function () {
				slosm_test_located( 1, '52.2297', '21.0122' );
				$GLOBALS['slosm_stub']['object_terms'][1] = new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );

				$calls = 0;
				$repo  = new Store_Repository(
					function () use ( &$calls ) {
						$calls++;

						return array( slosm_test_row( 1, 'Warszawa' ) );
					}
				);

				$early = $repo->find_all();

				// Called before init — a plugin reading locations at
				// plugins_loaded, a badly ordered hook — every taxonomy call
				// fails and every location comes back with no categories. The
				// caller still gets its locations; what nobody gets is a map with
				// no filter chips cached for a day.
				assert_same( array( 1 ), slosm_test_ids( $early ) );
				assert_same( array(), $early[0]->categories );
				assert_false( array_key_exists( $repo->cache_key(), $GLOBALS['slosm_stub']['transients'] ) );

				// init runs, the taxonomy registers, and the same request asks
				// again. Nothing was memoised either, so this answer is right.
				slosm_test_seed( 1, array(), array( 'Kawiarnia' ) );

				$later = $repo->find_all();

				assert_same( array( 'Kawiarnia' ), $later[0]->categories );
				assert_same( 2, $calls );
				assert_true( array_key_exists( $repo->cache_key(), $GLOBALS['slosm_stub']['transients'] ) );
			}
		);

		it(
			'says so when the payload cannot be written to the cache',
			function () {
				slosm_test_located( 1, '52.2297', '21.0122' );

				$failures = array();
				add_action(
					'slosm_cache_write_failed',
					static function ( $key, $count ) use ( &$failures ) {
						$failures[] = array( $key, $count );
					}
				);

				// What an object cache does with an item over its size limit:
				// Memcached's default is 1 MB, which this payload reaches at
				// roughly 4,000 locations. WordPress reports it as a plain false
				// and says nothing more, so code that ignores the return rebuilds
				// on every request for the largest sites, invisibly.
				$GLOBALS['slosm_stub']['transient_failure'] = true;

				$calls  = 0;
				$loader = function () use ( &$calls ) {
					$calls++;

					return array( slosm_test_row( 1, 'Warszawa' ) );
				};

				$repo = new Store_Repository( $loader );
				$repo->find_all();
				$repo->find_all();

				assert_same( 1, count( $failures ) );
				assert_same( $repo->cache_key(), $failures[0][0] );
				assert_same( 1, $failures[0][1] );
				assert_false( array_key_exists( $repo->cache_key(), $GLOBALS['slosm_stub']['transients'] ) );

				// The memo is the mitigation, and it is all there is: one rebuild
				// per request rather than one per call, and the next request pays
				// again.
				assert_same( 1, $calls );

				( new Store_Repository( $loader ) )->find_all();

				assert_same( 2, $calls );
			}
		);

		it(
			'caches the map payload and not the whole record',
			function () {
				slosm_test_seed(
					1,
					array(
						'_slosm_lat'   => '52.2297',
						'_slosm_lng'   => '21.0122',
						'_slosm_city'  => 'Warszawa',
						'_slosm_hours' => "Mon 9-17\nTue 9-17",
						'_slosm_phone' => '+48 22 000 00 00',
					),
					array( 'Kawiarnia' )
				);

				$repo = new Store_Repository(
					function () {
						return array( slosm_test_row( 1, 'Warszawa', 'Opis kawiarni.' ) );
					}
				);

				$repo->find_all();

				$payload = $GLOBALS['slosm_stub']['transients'][ $repo->cache_key() ]['value'];
				$keys    = array_keys( $payload[0] );
				sort( $keys );

				// This array ships to every visitor. The seven keys are pinned
				// against a literal, so an eighth field is a decision somebody
				// has to make here rather than a line added to the mapping.
				assert_same(
					array( 'address', 'categories', 'city', 'id', 'lat', 'lng', 'name' ),
					$keys
				);
			}
		);

		it(
			'keys the cached payload from zero so it ships as a json array',
			function () {
				slosm_test_located( 1, '52.2297', '21.0122' );
				slosm_test_located( 2, '', '' );
				slosm_test_located( 3, '50.0647', '19.9450' );

				$repo = new Store_Repository(
					function () {
						return array(
							slosm_test_row( 1, 'Warszawa' ),
							slosm_test_row( 2, 'Nowy' ),
							slosm_test_row( 3, 'Kraków' ),
						);
					}
				);

				$repo->find_all();

				$payload = $GLOBALS['slosm_stub']['transients'][ $repo->cache_key() ]['value'];

				// A gap in the keys is not a cosmetic detail. json_encode()
				// turns array( 0 => ..., 2 => ... ) into an object keyed "0" and
				// "2", and the map's JavaScript iterates a list.
				assert_same( array( 0, 1 ), array_keys( $payload ) );
				assert_contains( '[{"id":1', wp_json_encode( $payload ) );
			}
		);

		it(
			'gives a populated payload a day and rebuilds when it runs out',
			function () {
				slosm_test_located( 1, '52.2297', '21.0122' );

				$calls  = 0;
				$loader = function () use ( &$calls ) {
					$calls++;

					return array( slosm_test_row( 1, 'Warszawa' ) );
				};

				$repo = new Store_Repository( $loader );
				$repo->find_all();

				$stored = $GLOBALS['slosm_stub']['transients'][ $repo->cache_key() ];

				// A day, written as a number rather than as the class's own
				// constant, so the assertion does not agree with whatever the
				// class happens to say. Task 8 hooks the real invalidation; this
				// lifetime is only the backstop for a change that missed a hook,
				// and a payload that never expires would keep such a site wrong
				// forever.
				assert_same( 86400, $stored['ttl'] );

				slosm_stub_advance_time( 86399.0 );
				( new Store_Repository( $loader ) )->find_all();

				assert_same( 1, $calls, 'the payload expired early' );

				// Past the ttl the transient is gone, which is the behaviour the
				// number is supposed to buy. Advancing the clock is the only way
				// to see it: the recorded ttl on its own proves a number was
				// passed, not that anything honours it.
				slosm_stub_advance_time( 2.0 );
				( new Store_Repository( $loader ) )->find_all();

				assert_same( 2, $calls, 'the payload outlived its ttl' );
			}
		);

		it(
			'caches an empty payload, but only for a few minutes',
			function () {
				$calls  = 0;
				$loader = function () use ( &$calls ) {
					$calls++;

					return array();
				};

				$repo = new Store_Repository( $loader );
				$repo->find_all();

				// Empty is cached at all because a site with no locations yet, or
				// one where every location is still unplaced, would otherwise
				// rebuild on every page load. array_key_exists(), because
				// get_transient() cannot tell an empty payload from a miss.
				assert_true( array_key_exists( $repo->cache_key(), $GLOBALS['slosm_stub']['transients'] ) );
				assert_same( array(), $repo->find_all() );
				assert_same( 1, $calls );

				// Five minutes rather than a day, because empty is also what
				// every quiet failure produces — a misfiring pre_get_posts, a
				// query before the post type registered — and Task 8's hooks
				// cannot rescue any of them, since nothing is being saved.
				assert_same( 300, $GLOBALS['slosm_stub']['transients'][ $repo->cache_key() ]['ttl'] );

				slosm_stub_advance_time( 301.0 );
				( new Store_Repository( $loader ) )->find_all();

				assert_same( 2, $calls, 'an empty payload outlived its short ttl' );
			}
		);

		it(
			'rebuilds when the cache holds something that is not a payload',
			function () {
				slosm_test_located( 1, '52.2297', '21.0122' );

				$calls = 0;
				$repo  = new Store_Repository(
					function () use ( &$calls ) {
						$calls++;

						return array( slosm_test_row( 1, 'Warszawa' ) );
					}
				);

				// Whatever put this here — another plugin on the same key, an
				// object cache handing back a truncated value, a payload written
				// by an older version — a fatal on a front-end page is not the
				// answer. Rebuilding is.
				set_transient( $repo->cache_key(), 'not a payload', 60 );

				$stores = $repo->find_all();

				assert_same( 1, $calls );
				assert_same( array( 1 ), slosm_test_ids( $stores ) );
			}
		);

		it(
			'skips a cached row it cannot read instead of failing the page',
			function () {
				$repo = new Store_Repository(
					function () {
						return array();
					}
				);

				set_transient(
					$repo->cache_key(),
					array(
						'not a row',
						array(
							'id'   => 4,
							'name' => 'Warszawa',
							'lat'  => 52.2297,
							'lng'  => 21.0122,
						),
					),
					60
				);

				assert_same( array( 4 ), slosm_test_ids( $repo->find_all() ) );
			}
		);

		it(
			'skips a loaded row that is not a post',
			function () {
				slosm_test_located( 1, '52.2297', '21.0122' );

				$repo = new Store_Repository(
					function () {
						// What a loader asked for 'fields' => 'ids' would return,
						// and what a stray null in a filtered list looks like.
						return array( 1, null, slosm_test_row( 1, 'Warszawa' ) );
					}
				);

				assert_same( array( 1 ), slosm_test_ids( $repo->find_all() ) );
			}
		);

		it(
			'hands back Store objects rebuilt from the cached rows',
			function () {
				slosm_test_seed(
					1,
					array(
						'_slosm_lat'     => '52.2297',
						'_slosm_lng'     => '21.0122',
						'_slosm_address' => 'Nowy Świat 1',
						'_slosm_city'    => 'Warszawa',
						'_slosm_phone'   => '+48 22 000 00 00',
					),
					array( 'Kawiarnia' )
				);

				$repo   = new Store_Repository(
					function () {
						return array( slosm_test_row( 1, 'Kawiarnia', 'Opis kawiarni.' ) );
					}
				);
				$stores = $repo->find_all();

				assert_true( $stores[0] instanceof Store );
				assert_same( 'Kawiarnia', $stores[0]->name );
				assert_same( 52.2297, $stores[0]->lat );
				assert_same( 'Nowy Świat 1', $stores[0]->address );
				assert_same( array( 'Kawiarnia' ), $stores[0]->categories );

				// Everything outside the seven lean keys is empty, and that is
				// the contract rather than a defect: a caller that needs the
				// phone number or the description asks for one location by id.
				// A test asserting these were populated would be asking for the
				// full record to ship to every visitor.
				assert_same( '', $stores[0]->phone );
				assert_same( '', $stores[0]->description );
			}
		);

		it(
			'does not let an argument reach the loader and poison the shared payload',
			function () {
				slosm_test_located( 1, '52.2297', '21.0122' );
				slosm_test_located( 2, '50.0647', '19.9450' );

				$calls  = 0;
				$loader = function ( array $args = array() ) use ( &$calls ) {
					// Declared with a parameter, and it honours it — which is
					// exactly what a real loader would do if find_all() handed
					// its arguments on.
					$calls++;

					$rows = array(
						slosm_test_row( 1, 'Warszawa' ),
						slosm_test_row( 2, 'Kraków' ),
					);

					return isset( $args['category'] ) ? array( $rows[0] ) : $rows;
				};

				// The filtered call comes first on purpose. $args is reserved,
				// and this case is what keeps that honest: one key serves every
				// caller in a language, so a forwarded filter writes one
				// visitor's shorter list under the key every other visitor
				// reads, and the map then hides locations from everybody. When a
				// filter does arrive it has to be applied to the loaded list in
				// PHP, after the read.
				$filtered = ( new Store_Repository( $loader ) )->find_all( array( 'category' => 'kawiarnia' ) );
				$all      = ( new Store_Repository( $loader ) )->find_all();

				assert_same( array( 1, 2 ), slosm_test_ids( $filtered ) );
				assert_same( array( 1, 2 ), slosm_test_ids( $all ) );
				assert_same( 1, $calls );
			}
		);

		it(
			'asks WordPress for every published location when it is given no loader',
			function () {
				$GLOBALS['slosm_stub']['posts'] = array( slosm_test_row( 1, 'Warszawa' ) );
				slosm_test_located( 1, '52.2297', '21.0122' );

				$stores = ( new Store_Repository() )->find_all();

				assert_same( array( 1 ), slosm_test_ids( $stores ) );

				$query = $GLOBALS['slosm_stub']['post_queries'][0];

				// get_posts() defaults to five posts of type 'post'. Left alone
				// it would put five blog entries on the map and stop there, and
				// a site with six branches would simply be missing one with
				// nothing to say so.
				assert_same( 'slosm_store', $query['post_type'] );
				assert_same( -1, $query['numberposts'] );
				assert_same( 'publish', $query['post_status'] );

				// get_posts() is the one WordPress query function that suppresses
				// filters by default, which would take WPML and Polylang out of
				// the query and put every language's locations on every
				// language's map.
				assert_false( $query['suppress_filters'] );

				// And it orders by date, newest first, which is an order nobody
				// reading a list of branches has any use for. Alphabetical is
				// what the results list shows until a visitor gives a position.
				assert_same( 'title', $query['orderby'] );
				assert_same( 'ASC', $query['order'] );

				assert_same( 1, count( $GLOBALS['slosm_stub']['post_queries'] ) );
			}
		);
	}
);

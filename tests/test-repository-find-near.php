<?php
/**
 * Pins proximity search: the two paths into it, and what comes back from both.
 *
 * find_near() has two ways of answering and picks between them on its own. When
 * the lean payload for this language is already loaded — in the memo or in the
 * transient — it filters and sorts that list in PHP and asks storage nothing.
 * When it is not, it asks a second injected callable for the rows inside
 * Geo::bounding_box() and measures those. Which path ran is invisible from the
 * result, so every case about it counts invocations of both seams; comparing
 * the locations that came back would pass either way.
 *
 * Three traps this file is shaped around
 * --------------------------------------
 * The distance one. A case that asserts ids and calls itself a distance test
 * passes with the distance never attached, because ids come back regardless.
 * So the distance cases read $store->distance and compare it against a figure
 * written out here, and one of them asserts a null distance is not what arrives.
 *
 * The prefilter one. The bounding box is a superset of the search circle — its
 * corners lie well outside the radius — so a "within the radius" case whose
 * fixture is entirely inside the box proves only that the box works, and would
 * stay green with the exact distance filter deleted. 'drops a location inside
 * the bounding box but outside the radius' stages a location in that gap and
 * asserts, from the box the repository actually computed, that it really is in
 * the gap.
 *
 * The shared-payload one. There is one cache key per language and one
 * flush_cache(), so a search that narrows the loader would write one visitor's
 * shorter list under the key every other visitor reads. find_near() must never
 * touch the shared payload's loader and must never write the transient, which
 * is what the bounded-path case asserts on top of its invocation counts.
 *
 * One request, one repository
 * ---------------------------
 * The repository memoises the payload for the life of an instance, so a case
 * about the transient builds a second repository over the same loaders — that
 * is what the next request does. Cases about the memo reuse the instance and
 * say so.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-geo.php';
require_once dirname( __DIR__ ) . '/includes/class-store.php';
require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
require_once dirname( __DIR__ ) . '/includes/class-store-repository.php';

use Asymetria\StoreLocator\Geo;
use Asymetria\StoreLocator\Store;
use Asymetria\StoreLocator\Store_Repository;

/*
 * The geography every case below is measured against, verified with
 * Geo::distance() from 51.4027, 21.1471 (Radom) before any assertion was
 * written, because an ordering assertion over distances nobody checked is an
 * assertion about a guess:
 *
 *   Warszawa   92.42 km    57.43 mi
 *   Łódź      123.39 km
 *   Kraków    171.15 km
 *   Wrocław   287.76 km
 *
 * So a 200 km search holds the first three and drops the fourth, and the three
 * it holds are far enough apart that the order cannot be a coincidence. The
 * plan's "Kraków is about 152 km" is simply wrong — it is not the road distance
 * either, which is roughly 180 km — and the great-circle distance this plugin
 * measures is 171.15 km, which is why the radius here is 200 and not 150.
 */

if ( ! function_exists( 'slosm_near_row' ) ) {
	/**
	 * One row shaped the way a post loader hands them over.
	 *
	 * @param int    $id    Post id.
	 * @param string $title Post title.
	 * @return object
	 */
	function slosm_near_row( int $id, string $title = '' ): object {
		return (object) array(
			'ID'           => $id,
			'post_title'   => $title,
			'post_content' => '',
			'post_type'    => 'slosm_store',
			'post_status'  => 'publish',
		);
	}
}

if ( ! function_exists( 'slosm_near_place' ) ) {
	/**
	 * Stages one location's coordinates and hands back its row.
	 *
	 * Coordinates go in as strings, which is how post meta stores them, and the
	 * meta keys are written out in full rather than taken from the repository's
	 * own table — a fixture built from the constant under test agrees with any
	 * typo in it.
	 *
	 * @param int    $id    Post id.
	 * @param string $title Post title.
	 * @param string $lat   Latitude as post meta stores it.
	 * @param string $lng   Longitude as post meta stores it.
	 * @return object The row a loader would return for it.
	 */
	function slosm_near_place( int $id, string $title, string $lat, string $lng ): object {
		update_post_meta( $id, '_slosm_lat', $lat );
		update_post_meta( $id, '_slosm_lng', $lng );

		return slosm_near_row( $id, $title );
	}
}

if ( ! function_exists( 'slosm_near_ids' ) ) {
	/**
	 * The ids of a list of locations, for comparing a whole result in one line.
	 *
	 * @param Store[] $stores Locations.
	 * @return int[]
	 */
	function slosm_near_ids( array $stores ): array {
		$ids = array();

		foreach ( $stores as $store ) {
			$ids[] = $store->id;
		}

		return $ids;
	}
}

if ( ! function_exists( 'slosm_near_in_box' ) ) {
	/**
	 * Whether a point lies inside a bounding box.
	 *
	 * Written out rather than asked of Geo, which has no such method: this is
	 * what the SQL BETWEEN does with the four numbers, and a case proving a
	 * location survived the prefilter has to model the prefilter.
	 *
	 * @param array $box Box from Geo::bounding_box().
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return bool
	 */
	function slosm_near_in_box( array $box, float $lat, float $lng ): bool {
		if ( $lat < $box['min_lat'] || $lat > $box['max_lat'] ) {
			return false;
		}

		return $lng >= $box['min_lng'] && $lng <= $box['max_lng'];
	}
}

describe(
	'store distance',
	function () {

		it(
			'copies a location with a distance and leaves the original alone',
			function () {
				$store = Store::from_array(
					array(
						'id'         => 7,
						'name'       => 'Warszawa',
						'lat'        => '52.2297',
						'lng'        => '21.0122',
						'categories' => array( 'Kawiarnia' ),
					)
				);

				$found = $store->with_distance( 92.42 );

				assert_same( 92.42, $found->distance );

				// The whole point of a copy. A location is a value and the same
				// object is reachable from the memo and from anything else
				// holding it, so a with_distance() that assigned in place would
				// leave one visitor's search distance on the shared list.
				assert_same( null, $store->distance );

				// And the copy is the same location: nothing is rebuilt field by
				// field, so no field can be dropped on the way through.
				assert_same( 7, $found->id );
				assert_same( 'Warszawa', $found->name );
				assert_same( 52.2297, $found->lat );
				assert_same( array( 'Kawiarnia' ), $found->categories );
			}
		);

		it(
			'keeps the distance out of both array shapes',
			function () {
				$found = Store::from_array( array( 'id' => 7 ) )->with_distance( 92.42 );

				$lean = array_keys( $found->to_lean_array() );
				$full = array_keys( $found->to_full_array() );
				sort( $lean );

				// The lean shape is what the repository caches and what every
				// visitor in a language is served. One visitor's distance stored
				// there would be handed to the next visitor as their own, with
				// nothing to announce it, so the seven keys are pinned against a
				// literal here as well as in find_all's own case — this is the
				// object that actually has a distance to leak.
				assert_same(
					array( 'address', 'categories', 'city', 'id', 'lat', 'lng', 'name' ),
					$lean
				);

				// The record shape is not the place either: distance is a fact
				// about a search, not about a location, and to_full_array() is
				// what a stored record round-trips through.
				assert_false( in_array( 'distance', $full, true ) );
			}
		);
	}
);

describe(
	'store repository find_near',
	function () {

		it(
			'returns only locations inside the radius, nearest first',
			function () {
				$rows = array(
					slosm_near_place( 1, 'Kraków', '50.0647', '19.9450' ),
					slosm_near_place( 2, 'Łódź', '51.7592', '19.4560' ),
					slosm_near_place( 3, 'Warszawa', '52.2297', '21.0122' ),
					slosm_near_place( 4, 'Wrocław', '51.1079', '17.0385' ),
				);

				$repo = new Store_Repository(
					null,
					static function ( array $box ) use ( $rows ): array {
						return $rows;
					}
				);

				// Given in the alphabetical order a loader returns them in, which
				// is the exact reverse of the distance order, so an
				// implementation that forgot to sort cannot pass by accident.
				// Wrocław is 287.76 km out and is the only one the radius drops.
				assert_same(
					array( 3, 2, 1 ),
					slosm_near_ids( $repo->find_near( 51.4027, 21.1471, 200.0, 10 ) )
				);
			}
		);

		it(
			'honours the limit',
			function () {
				$rows = array(
					slosm_near_place( 1, 'Kraków', '50.0647', '19.9450' ),
					slosm_near_place( 2, 'Łódź', '51.7592', '19.4560' ),
					slosm_near_place( 3, 'Warszawa', '52.2297', '21.0122' ),
				);

				$repo = new Store_Repository(
					null,
					static function ( array $box ) use ( $rows ): array {
						return $rows;
					}
				);

				// Two of the three, and the two nearest: the slice happens after
				// the sort, never before it. Cutting the list first would hand
				// back whichever two the loader happened to return, which here
				// would be Kraków and Łódź — the far pair.
				assert_same(
					array( 3, 2 ),
					slosm_near_ids( $repo->find_near( 51.4027, 21.1471, 200.0, 2 ) )
				);
			}
		);

		it(
			'attaches the distance to each result',
			function () {
				$rows = array(
					slosm_near_place( 1, 'Kraków', '50.0647', '19.9450' ),
					slosm_near_place( 3, 'Warszawa', '52.2297', '21.0122' ),
				);

				$repo = new Store_Repository(
					null,
					static function ( array $box ) use ( $rows ): array {
						return $rows;
					}
				);

				$found = $repo->find_near( 51.4027, 21.1471, 200.0, 10 );

				// Figures written out rather than measured here again: a case
				// that recomputed them with Geo::distance() would agree with a
				// find_near() that measured from the wrong point or in the wrong
				// unit, since both sides would make the same mistake.
				assert_close( 92.42, (float) $found[0]->distance, 0.01 );
				assert_close( 171.15, (float) $found[1]->distance, 0.01 );

				// A null distance is the failure this case exists for: the ids
				// and the order above come back whether or not anything was
				// attached, so the type is asserted as well as the value.
				assert_false( null === $found[0]->distance );
			}
		);

		it(
			'measures in the unit it was asked for, and draws the box in it too',
			function () {
				$rows = array(
					slosm_near_place( 1, 'Kraków', '50.0647', '19.9450' ),
					slosm_near_place( 3, 'Warszawa', '52.2297', '21.0122' ),
				);

				$box  = array();
				$repo = new Store_Repository(
					null,
					static function ( array $asked ) use ( $rows, &$box ): array {
						$box = $asked;

						return $rows;
					}
				);

				// 124 miles is 199.6 km, so both locations are inside the search:
				// 57.43 miles and 106.35 of them, not 92.42 and 171.15. A unit
				// that reached the box but not the distance — or the other way
				// round — would shrink one of them by a factor of 1.6 and nothing
				// downstream could tell, because the numbers and the circle would
				// still agree with each other.
				$found = $repo->find_near( 51.4027, 21.1471, 124.0, 10, 'mi' );

				assert_same( array( 3, 1 ), slosm_near_ids( $found ) );
				assert_close( 57.43, (float) $found[0]->distance, 0.01 );
				assert_close( 106.35, (float) $found[1]->distance, 0.01 );

				// The box has to be 124 miles wide as well. Drawn as though the
				// radius were 124 kilometres it would stop 148 km short of
				// Kraków, and in production the prefilter is where rows stop
				// existing: the SQL would simply not return a location that is
				// well inside the radius the visitor asked for.
				assert_true(
					slosm_near_in_box( $box, 50.0647, 19.945 ),
					'the box was drawn in kilometres for a search in miles'
				);
			}
		);

		it(
			'drops a location inside the bounding box but outside the radius',
			function () {
				$rows = array(
					slosm_near_place( 3, 'Warszawa', '52.2297', '21.0122' ),
					slosm_near_place( 8, 'Róg pudełka', '52.2500', '22.5000' ),
				);

				$box  = array();
				$repo = new Store_Repository(
					null,
					static function ( array $asked ) use ( $rows, &$box ): array {
						$box = $asked;

						return $rows;
					}
				);

				$found = $repo->find_near( 51.4027, 21.1471, 100.0, 10 );

				// The box the repository actually computed is what makes this a
				// test of the exact filter rather than of the prefilter: 52.25,
				// 22.50 is 132.36 km away and the search is 100 km, and it is
				// inside the box the SQL BETWEEN would have used. Delete the
				// radius check and this location comes back.
				assert_true( slosm_near_in_box( $box, 52.25, 22.5 ) );
				assert_close( 132.36, Geo::distance( 51.4027, 21.1471, 52.25, 22.5 ), 0.01 );

				assert_same( array( 3 ), slosm_near_ids( $found ) );
			}
		);

		it(
			'never returns a location that cannot be placed on a map',
			function () {
				$rows = array(
					slosm_near_place( 3, 'Warszawa', '52.2297', '21.0122' ),
					slosm_near_place( 5, 'Bez współrzędnych', '', '' ),
					slosm_near_place( 6, 'Nieudany geokoding', '0', '0' ),
					slosm_near_place( 7, 'Przecinek', '51,4', '21,1' ),
					slosm_near_place( 9, 'Zatoka Gwinejska', '0.6', '0.6' ),
				);

				$repo = new Store_Repository(
					null,
					static function ( array $box ) use ( $rows ): array {
						return $rows;
					}
				);

				// In production the meta_query would not match the unplaced
				// three, but the prefilter is not where this is decided: a comma
				// decimal is a string MySQL casts to 51, which lands inside
				// plenty of boxes, and a failed geocode that wrote zeros is a
				// real pair of numbers.
				assert_same(
					array( 3 ),
					slosm_near_ids( $repo->find_near( 51.4027, 21.1471, 200.0, 10 ) )
				);

				// And the search that makes the guard's absence visible. Every
				// unreadable coordinate becomes 0.0 the moment anything measures
				// it, which is a real point in the Gulf of Guinea — so from a
				// Polish search they are simply far away, and a find_near() that
				// never checked has_coordinates() would look correct. Searched
				// from 0.5, 0.5 they are 79 km off and inside the radius, and
				// only the one location genuinely there comes back.
				assert_same(
					array( 9 ),
					slosm_near_ids( $repo->find_near( 0.5, 0.5, 200.0, 10 ) )
				);
			}
		);

		it(
			'asks the bounded loader when no payload is cached, and caches nothing',
			function () {
				$rows = array( slosm_near_place( 3, 'Warszawa', '52.2297', '21.0122' ) );

				$loader_calls  = 0;
				$bounded_calls = 0;

				$loader = function () use ( &$loader_calls ): array {
					$loader_calls++;

					return array();
				};

				$bounded = function ( array $box ) use ( $rows, &$bounded_calls ): array {
					$bounded_calls++;

					return $rows;
				};

				$repo  = new Store_Repository( $loader, $bounded );
				$found = $repo->find_near( 51.4027, 21.1471, 200.0, 10 );

				assert_same( array( 3 ), slosm_near_ids( $found ) );
				assert_same( 1, $bounded_calls, 'nothing was cached and no bounded query ran' );

				// The two assertions that keep the seams apart. One key serves
				// every visitor in a language, so a search that went through the
				// shared loader — or wrote what it found under that key — would
				// leave every other visitor's map holding one visitor's search
				// results.
				assert_same( 0, $loader_calls, 'a search went through the shared payload loader' );
				assert_false(
					array_key_exists( $repo->cache_key(), $GLOBALS['slosm_stub']['transients'] ),
					'a search wrote the shared payload'
				);
			}
		);

		it(
			'filters the payload in PHP when it is already loaded',
			function () {
				$rows = array(
					slosm_near_place( 1, 'Kraków', '50.0647', '19.9450' ),
					slosm_near_place( 3, 'Warszawa', '52.2297', '21.0122' ),
					slosm_near_place( 4, 'Wrocław', '51.1079', '17.0385' ),
				);

				$loader_calls  = 0;
				$bounded_calls = 0;

				$loader = function () use ( $rows, &$loader_calls ): array {
					$loader_calls++;

					return $rows;
				};

				$bounded = function ( array $box ) use ( &$bounded_calls ): array {
					$bounded_calls++;

					return array();
				};

				// The map has already been rendered this way round on every page
				// the shortcode is on: find_all() built and cached the payload,
				// and the search that follows it is the second request.
				( new Store_Repository( $loader, $bounded ) )->find_all();

				$next  = new Store_Repository( $loader, $bounded );
				$found = $next->find_near( 51.4027, 21.1471, 200.0, 10 );

				assert_same( array( 3, 1 ), slosm_near_ids( $found ) );

				// Counted, not assumed. The result above is what a bounded query
				// over the same rows would also produce, so only the invocation
				// counts say which path answered.
				assert_same( 0, $bounded_calls, 'the cached payload was ignored and a query ran' );
				assert_same( 1, $loader_calls, 'the payload was rebuilt for a search' );

				// And again from the memo: the transient is deleted behind the
				// repository's back, so only the list this request already holds
				// can answer. Two searches on one page cost nothing.
				delete_transient( $next->cache_key() );

				assert_same(
					array( 3, 1 ),
					slosm_near_ids( $next->find_near( 51.4027, 21.1471, 200.0, 10 ) )
				);
				assert_same( 0, $bounded_calls, 'the memo was ignored and a query ran' );
				assert_same( 1, $loader_calls, 'the memo was ignored and the payload rebuilt' );
			}
		);

		it(
			'leaves the loaded payload exactly as it found it',
			function () {
				$rows = array(
					slosm_near_place( 1, 'Kraków', '50.0647', '19.9450' ),
					slosm_near_place( 3, 'Warszawa', '52.2297', '21.0122' ),
					slosm_near_place( 4, 'Wrocław', '51.1079', '17.0385' ),
				);

				$loader_calls = 0;
				$loader       = function () use ( $rows, &$loader_calls ): array {
					$loader_calls++;

					return $rows;
				};

				$repo = new Store_Repository(
					$loader,
					static function ( array $box ): array {
						return array();
					}
				);

				$repo->find_all();

				// One search, narrow enough that it matches a single location.
				assert_same(
					array( 3 ),
					slosm_near_ids( $repo->find_near( 51.4027, 21.1471, 100.0, 10 ) )
				);

				// This is the invariant the whole two-seam design exists for,
				// stated in one place rather than left to be caught in passing by
				// a case about something else. A find_near() that wrote its own
				// results back into the memo — a one-line mistake, and an
				// appealing one, since both hold lean rows — would leave this
				// request's map showing the one location that search matched,
				// and would leave the cached payload behind it intact so that
				// nothing outside the request could see it happen.
				assert_same( array( 1, 3, 4 ), slosm_near_ids( $repo->find_all() ) );
				assert_same( 1, $loader_calls, 'the payload was rebuilt' );

				// And the transient still holds all three, so the next request
				// is not served a search either.
				$payload = $GLOBALS['slosm_stub']['transients'][ $repo->cache_key() ]['value'];

				assert_same( 3, count( $payload ) );
			}
		);

		it(
			'finds nothing for a radius that is not a number, on either path',
			function () {
				$rows = array(
					slosm_near_place( 1, 'Kraków', '50.0647', '19.9450' ),
					slosm_near_place( 3, 'Warszawa', '52.2297', '21.0122' ),
					slosm_near_place( 4, 'Wrocław', '51.1079', '17.0385' ),
				);

				$loader = static function () use ( $rows ): array {
					return $rows;
				};

				$bounded = static function ( array $box ) use ( $rows ): array {
					return $rows;
				};

				// Every comparison against NAN is false, so an unguarded
				// "farther than the radius" check drops nothing at all and the
				// preloaded path answers a nonsensical search with the entire
				// site — while the bounded path answers the same search with
				// nothing, because Geo::bounding_box() already turns NAN into a
				// box one point wide. The bug is not the emptiness, it is that
				// the answer would depend on whether a transient happened to be
				// warm, which is the one thing both paths are built not to do.
				// Nothing is cached yet, so both of these take the bounded path.
				// A negative radius needs no guard of its own — no distance is
				// below zero — and it is pinned alongside NAN so that it stays
				// that way, and so that the two paths keep agreeing.
				$bounded_repo = new Store_Repository( $loader, $bounded );

				assert_same( array(), $bounded_repo->find_near( 51.4027, 21.1471, NAN, 10 ) );
				assert_same( array(), $bounded_repo->find_near( 51.4027, 21.1471, -5.0, 10 ) );

				// And now with the payload loaded, which is where an unguarded
				// NAN hands back every location on the site. The order matters:
				// find_all() writes the transient, so these two have to come
				// after the pair above or they would be the same path twice.
				$warm = new Store_Repository( $loader, $bounded );
				$warm->find_all();

				assert_same( array(), $warm->find_near( 51.4027, 21.1471, NAN, 10 ) );
				assert_same( array(), $warm->find_near( 51.4027, 21.1471, -5.0, 10 ) );
			}
		);

		it(
			'answers in the same shape whichever path ran',
			function () {
				update_post_meta( 3, '_slosm_address', 'Nowy Świat 1' );
				update_post_meta( 3, '_slosm_city', 'Warszawa' );
				update_post_meta( 3, '_slosm_phone', '+48 22 000 00 00' );
				update_post_meta( 3, '_slosm_hours', "Mon 9-17\nTue 9-17" );
				$GLOBALS['slosm_stub']['object_terms'][3] = array( 'Kawiarnia' );

				$rows = array( slosm_near_place( 3, 'Warszawa', '52.2297', '21.0122' ) );

				$loader = static function () use ( $rows ): array {
					return $rows;
				};

				$bounded = static function ( array $box ) use ( $rows ): array {
					return $rows;
				};

				$bounded_result   = ( new Store_Repository( $loader, $bounded ) )->find_near( 51.4027, 21.1471, 200.0, 10 );
				$warm             = new Store_Repository( $loader, $bounded );
				$warm->find_all();
				$preloaded_result = $warm->find_near( 51.4027, 21.1471, 200.0, 10 );

				// Both paths answer with the lean seven fields. Which path runs
				// depends on whether a payload happens to be cached, which
				// depends on the site, so a result shape that differed between
				// them would make every caller's behaviour depend on that — and
				// Task 10's list response would carry the phone number and the
				// opening hours of every match on one kind of site and not the
				// other. A caller that needs the whole record calls find_by_id().
				assert_same( 'Warszawa', $bounded_result[0]->city );
				assert_same( array( 'Kawiarnia' ), $bounded_result[0]->categories );
				assert_same( '', $bounded_result[0]->phone );
				assert_same( '', $bounded_result[0]->hours );

				assert_same(
					$preloaded_result[0]->to_full_array(),
					$bounded_result[0]->to_full_array()
				);
			}
		);

		it(
			'returns nothing, and asks nothing, for a limit below one',
			function () {
				$bounded_calls = 0;

				$repo = new Store_Repository(
					null,
					function ( array $box ) use ( &$bounded_calls ): array {
						$bounded_calls++;

						return array( slosm_near_place( 3, 'Warszawa', '52.2297', '21.0122' ) );
					}
				);

				// A caller asking for no results gets none without a query. -1
				// does not mean "everything" here the way it does to get_posts():
				// this is a count of results, and a silent "everything" is the
				// wrong answer to give a caller whose limit arrived from a
				// request parameter.
				assert_same( array(), $repo->find_near( 51.4027, 21.1471, 200.0, 0 ) );
				assert_same( array(), $repo->find_near( 51.4027, 21.1471, 200.0, -1 ) );
				assert_same( 0, $bounded_calls );
			}
		);

		it(
			'asks WordPress for the box in a meta_query that keeps every decimal place',
			function () {
				$GLOBALS['slosm_stub']['posts'] = array( slosm_near_place( 3, 'Warszawa', '52.2297', '21.0122' ) );

				$found = ( new Store_Repository() )->find_near( 51.4027, 21.1471, 200.0, 10 );

				assert_same( array( 3 ), slosm_near_ids( $found ) );

				$query = $GLOBALS['slosm_stub']['post_queries'][0];
				$meta  = $query['meta_query'];

				// DECIMAL(10,6) is the whole point of this case. 'NUMERIC' casts
				// to SIGNED and truncates 52.2297 to 52 — about 25 km of
				// latitude — and a bare 'DECIMAL' is DECIMAL(10,2), about 1.1 km.
				// Both give a box that matches the wrong rows and says nothing.
				// AND, and it has to be asserted: OR is correctness-preserving,
				// because the exact filter in PHP decides either way, so nothing
				// else here would notice the flip. What it costs is the whole
				// point of the query — the latitude band stops cutting anything,
				// and every location on the site inside the longitude band
				// crosses into PHP on every search.
				assert_same( 'AND', $meta['relation'] );

				assert_same( '_slosm_lat', $meta[0]['key'] );
				assert_same( 'BETWEEN', $meta[0]['compare'] );
				assert_same( 'DECIMAL(10,6)', $meta[0]['type'] );
				assert_same( '_slosm_lng', $meta[1]['key'] );
				assert_same( 'BETWEEN', $meta[1]['compare'] );
				assert_same( 'DECIMAL(10,6)', $meta[1]['type'] );

				// The box has to hold the circle. Four points that Geo::distance()
				// — pinned in its own file against figures from a different
				// formula — puts inside a 200 km radius, so a box that excluded
				// any of them would be cutting locations before anything could
				// measure them.
				$box = array(
					'min_lat' => (float) $meta[0]['value'][0],
					'max_lat' => (float) $meta[0]['value'][1],
					'min_lng' => (float) $meta[1]['value'][0],
					'max_lng' => (float) $meta[1]['value'][1],
				);

				assert_true( slosm_near_in_box( $box, 53.15, 21.1471 ), 'the box falls short to the north' );
				assert_true( slosm_near_in_box( $box, 49.65, 21.1471 ), 'the box falls short to the south' );
				assert_true( slosm_near_in_box( $box, 51.4027, 23.9 ), 'the box falls short to the east' );
				assert_true( slosm_near_in_box( $box, 51.4027, 18.4 ), 'the box falls short to the west' );

				// Not limited in SQL. The order is by distance and MySQL does not
				// know the distances, so a query that cut the rows to the limit
				// first would cut them in title order and drop nearer locations.
				assert_same( -1, $query['numberposts'] );
				assert_same( 'slosm_store', $query['post_type'] );
				assert_same( 'publish', $query['post_status'] );
				assert_false( $query['suppress_filters'] );

				assert_same( 1, count( $GLOBALS['slosm_stub']['post_queries'] ) );
			}
		);
	}
);

describe(
	'store repository find_by_id',
	function () {

		before_each(
			function () {
				update_post_meta( 3, '_slosm_lat', '52.2297' );
				update_post_meta( 3, '_slosm_lng', '21.0122' );
				update_post_meta( 3, '_slosm_address', 'Nowy Świat 1' );
				update_post_meta( 3, '_slosm_city', 'Warszawa' );
				update_post_meta( 3, '_slosm_phone', '+48 22 000 00 00' );
				update_post_meta( 3, '_slosm_email', 'kawa@example.test' );
				update_post_meta( 3, '_slosm_hours', "Mon 9-17\nTue 9-17" );
				update_post_meta( 3, '_slosm_lat_locked', '1' );
				$GLOBALS['slosm_stub']['object_terms'][3] = array( 'Kawiarnia' );

				$GLOBALS['slosm_stub']['posts_by_id'][3] = (object) array(
					'ID'           => 3,
					'post_title'   => 'Warszawa',
					'post_content' => 'Opis kawiarni.',
					'post_type'    => 'slosm_store',
					'post_status'  => 'publish',
				);
			}
		);

		it(
			'reads the whole record even when the lean payload is cached',
			function () {
				$loader = static function (): array {
					return array( slosm_near_row( 3, 'Warszawa' ) );
				};

				$repo = new Store_Repository( $loader );

				// The payload is in the cache and in the memo, and it holds seven
				// keys. Answering out of it would hand back a Store whose
				// to_full_array() looks like a complete record and is missing the
				// phone, the hours, the email and the description — an HTTP 200
				// with the popup's content silently blank.
				$repo->find_all();

				$store = $repo->find_by_id( 3 );

				assert_true( $store instanceof Store );
				assert_same( '+48 22 000 00 00', $store->phone );
				assert_same( "Mon 9-17\nTue 9-17", $store->hours );
				assert_same( 'kawa@example.test', $store->email );
				assert_same( 'Opis kawiarni.', $store->description );

				// The seven lean fields agree with the record too, so this is a
				// full read rather than a partial one patched up.
				assert_same( 'Warszawa', $store->name );
				assert_same( 52.2297, $store->lat );
				assert_same( array( 'Kawiarnia' ), $store->categories );
				assert_true( $store->lat_locked );

				// And no distance: find_by_id() answers about a location, not
				// about a search.
				assert_same( null, $store->distance );
			}
		);

		it(
			'returns null for an id that is not a published location',
			function () {
				$GLOBALS['slosm_stub']['posts_by_id'][4] = (object) array(
					'ID'          => 4,
					'post_title'  => 'O nas',
					'post_type'   => 'page',
					'post_status' => 'publish',
				);

				$GLOBALS['slosm_stub']['posts_by_id'][5] = (object) array(
					'ID'          => 5,
					'post_title'  => 'Nowy oddział',
					'post_type'   => 'slosm_store',
					'post_status' => 'draft',
				);

				$repo = new Store_Repository();

				// A page is not a location, and answering with one would let
				// /stores/4 read any post on the site as though it were a branch.
				assert_same( null, $repo->find_by_id( 4 ) );

				// A draft is a location nobody has published yet. find_all()
				// ships published locations only, and the two have to agree or
				// the popup endpoint becomes a way to read unpublished content.
				assert_same( null, $repo->find_by_id( 5 ) );

				// And an id that is nothing at all.
				assert_same( null, $repo->find_by_id( 99 ) );
				assert_same( null, $repo->find_by_id( 0 ) );
			}
		);

		it(
			'returns null for a published location behind a post password',
			function () {
				$GLOBALS['slosm_stub']['posts_by_id'][6] = (object) array(
					'ID'            => 6,
					'post_title'    => 'Oddział zamknięty',
					'post_content'  => 'Adres wewnętrzny, nie dla klientów.',
					'post_type'     => 'slosm_store',
					'post_status'   => 'publish',
					'post_password' => 'sekret',
				);

				update_post_meta( 6, '_slosm_lat', '52.2297' );
				update_post_meta( 6, '_slosm_lng', '21.0122' );

				$repo = new Store_Repository();

				// to_store() maps post_content into description, so without the
				// check Task 10's /stores/6 hands the whole body to any
				// anonymous caller while the same content on the front end sits
				// behind a password form.
				assert_same( null, $repo->find_by_id( 6 ) );

				// The control, and it is the one that matters: every other thing
				// this record needs to be readable is in place, so the null above
				// is the password and nothing else. Drop the password and the
				// same row answers in full.
				$GLOBALS['slosm_stub']['posts_by_id'][6]->post_password = '';

				$store = $repo->find_by_id( 6 );

				assert_same( 6, $store->id );
				assert_same( 'Adres wewnętrzny, nie dla klientów.', $store->description );
			}
		);

		it(
			'reads a row with no post_password property at all, and raises no warning doing it',
			function () {
				// An injected loader builds rows by hand and slosm_near_row()
				// sets no post_password, so a missing property is the ordinary
				// case here rather than an odd one.
				//
				// The answer is not what this case is really about — a read
				// without the null-coalesce gives null, casts to '', and passes
				// the gate just the same. What it gives as well is "Undefined
				// property", and on a site with WP_DEBUG_DISPLAY on, that line
				// is printed into the body of the json response, where it breaks
				// the parse at the browser end with an error naming neither the
				// warning nor this line. So the warning is what is asserted, and
				// it takes a handler to see one: PHP warnings are not Throwable,
				// so it() would count a case that raised one as a pass.
				$GLOBALS['slosm_stub']['posts_by_id'][7] = (object) array(
					'ID'          => 7,
					'post_title'  => 'Bez pola',
					'post_type'   => 'slosm_store',
					'post_status' => 'publish',
				);

				$raised = array();

				set_error_handler(
					static function ( $errno, $message ) use ( &$raised ) {
						$raised[] = $message;

						return true;
					}
				);

				try {
					$store = ( new Store_Repository() )->find_by_id( 7 );
				} finally {
					restore_error_handler();
				}

				assert_same( array(), $raised, 'find_by_id() raised: ' . implode( '; ', $raised ) );

				assert_same( 7, $store->id );
				assert_same( 'Bez pola', $store->name );
			}
		);
	}
);

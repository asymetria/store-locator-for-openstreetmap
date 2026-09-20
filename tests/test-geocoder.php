<?php
/**
 * Pins the geocoder: what goes on the wire, what comes back, and what is never
 * written down.
 *
 * This is the class most able to lie. Every one of its interesting properties
 * is invisible from a return value — a cached answer looks exactly like a fresh
 * one, a rate limiter that never sleeps returns the same array as one that
 * does, and a failure cached for thirty days looks like a failure that was not
 * cached until a month later. So almost nothing here asserts on the result
 * alone.
 *
 * Four rules this file is built on
 * --------------------------------
 * 1. A cache case counts requests. wp_remote_get() records every call into
 *    $GLOBALS['slosm_stub']['http_requests'] and throws when nothing is queued,
 *    so "did not reach the network" is a number, not an inference. Queuing one
 *    response and calling twice proves the second call was served from the
 *    cache, because a second request would have thrown.
 *
 * 2. A "did not cache" case reads array_key_exists() on the transients array,
 *    never get_transient(). get_transient() answers false for a key that was
 *    never written and for a key holding false, exactly as WordPress does, so
 *    assert_false( get_transient( $key ) ) cannot tell "correctly declined to
 *    cache a rate-limit response" from "cached false for thirty days" — which
 *    is the failure that would break geocoding on a live site for a month.
 *
 * 3. A rate-limit case reads $GLOBALS['slosm_stub']['sleeps']. Nothing here
 *    measures wall-clock time: the clock and the sleeper are constructor
 *    arguments, the suite injects recorders for both, and a case asserts on
 *    what the code asked for rather than on what a busy machine happened to do.
 *
 * 4. A request case asserts on the recorded url and args. "Sends a User-Agent"
 *    and "url-encodes the query" are claims about bytes, and the only place
 *    those bytes exist is the recorded request.
 *
 * What this file deliberately does not do is make a single network request.
 * Every response is staged. An unqueued request is a RuntimeException from the
 * stub, which the framework reports as a failed case — that is the design, not
 * an accident: a caching bug shows up as an exception naming the url it tried
 * to fetch.
 *
 * On mbstring, and why the fixtures avoid it
 * ------------------------------------------
 * Normalisation lowercases with mb_strtolower() only when it exists and with
 * strtolower() otherwise, and the two disagree on non-ASCII letters: 'Ł' folds
 * under mbstring and does not without it. The CLI binaries here ship no
 * mbstring, so a fixture whose expected value depended on that fold would pass
 * here and fail on a host that has it. Every diacritic in this file is
 * therefore already lowercase ('ó', 'ś'), which both branches leave alone, and
 * the lowercasing itself is pinned on ASCII where the two agree.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

require_once dirname( __DIR__ ) . '/includes/class-geocoder.php';

use Asymetria\StoreLocator\Geocoder;

if ( ! function_exists( 'slosm_geocoder' ) ) {
	/**
	 * A geocoder wired to the stub clock and the stub sleeper.
	 *
	 * Production defaults to microtime( true ) and usleep(); a case that used
	 * those would have to sleep for real to prove the rate limiter, which is
	 * why the seam exists at all.
	 *
	 * @return Geocoder
	 */
	function slosm_geocoder(): Geocoder {
		return new Geocoder( 'slosm_stub_time', 'slosm_stub_sleep' );
	}
}

if ( ! function_exists( 'slosm_queue_response' ) ) {
	/**
	 * Stages one http response for the next wp_remote_get().
	 *
	 * @param string $body Response body, verbatim.
	 * @param int    $code Status code.
	 * @return void
	 */
	function slosm_queue_response( string $body, int $code = 200 ): void {
		$GLOBALS['slosm_stub']['http_queue'][] = array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
		);
	}
}

if ( ! function_exists( 'slosm_queue_nominatim' ) ) {
	/**
	 * Stages a Nominatim search response.
	 *
	 * @param array $results Result rows, encoded as the json Nominatim sends.
	 * @param int   $code    Status code.
	 * @return void
	 */
	function slosm_queue_nominatim( array $results, int $code = 200 ): void {
		slosm_queue_response( (string) wp_json_encode( $results ), $code );
	}
}

if ( ! function_exists( 'slosm_nominatim_hit' ) ) {
	/**
	 * One Nominatim result row.
	 *
	 * The coordinates default to strings and the key to 'lon', because that is
	 * what the service sends: a fixture using floats and 'lng' would make a
	 * parser that only handles floats and 'lng' look correct.
	 *
	 * @param mixed  $lat   Latitude as sent.
	 * @param mixed  $lon   Longitude as sent.
	 * @param string $label Display name.
	 * @return array
	 */
	function slosm_nominatim_hit( $lat = '52.2297', $lon = '21.0122', $label = 'Warszawa, Polska' ): array {
		$row = array(
			'place_id' => 12345,
			'lat'      => $lat,
			'lon'      => $lon,
		);

		if ( null !== $label ) {
			$row['display_name'] = $label;
		}

		return $row;
	}
}

if ( ! function_exists( 'slosm_photon_feature' ) ) {
	/**
	 * One Photon feature.
	 *
	 * GeoJSON order: coordinates are [ longitude, latitude ], which is the
	 * reverse of how everything else in this plugin writes a point, and they
	 * arrive as json numbers rather than as the strings Nominatim sends.
	 *
	 * @param mixed $lon        Longitude as sent.
	 * @param mixed $lat        Latitude as sent.
	 * @param array $properties Photon properties.
	 * @return array
	 */
	function slosm_photon_feature( $lon = 21.0122, $lat = 52.2297, array $properties = array() ): array {
		return array(
			'type'       => 'Feature',
			'geometry'   => array(
				'type'        => 'Point',
				'coordinates' => array( $lon, $lat ),
			),
			'properties' => array() === $properties ? array( 'name' => 'Warszawa', 'country' => 'Polska' ) : $properties,
		);
	}
}

if ( ! function_exists( 'slosm_queue_photon' ) ) {
	/**
	 * Stages a Photon response.
	 *
	 * @param array $features Features.
	 * @param int   $code     Status code.
	 * @return void
	 */
	function slosm_queue_photon( array $features, int $code = 200 ): void {
		slosm_queue_response(
			(string) wp_json_encode(
				array(
					'type'     => 'FeatureCollection',
					'features' => $features,
				)
			),
			$code
		);
	}
}

if ( ! function_exists( 'slosm_requests' ) ) {
	/**
	 * How many requests actually went out.
	 *
	 * @return int
	 */
	function slosm_requests(): int {
		return count( $GLOBALS['slosm_stub']['http_requests'] );
	}
}

if ( ! function_exists( 'slosm_last_url' ) ) {
	/**
	 * The url of the last recorded request.
	 *
	 * @return string
	 */
	function slosm_last_url(): string {
		$requests = $GLOBALS['slosm_stub']['http_requests'];

		return array() === $requests ? '' : (string) $requests[ count( $requests ) - 1 ]['url'];
	}
}

if ( ! function_exists( 'slosm_last_args' ) ) {
	/**
	 * The args of the last recorded request.
	 *
	 * @return array
	 */
	function slosm_last_args(): array {
		$requests = $GLOBALS['slosm_stub']['http_requests'];

		return array() === $requests ? array() : (array) $requests[ count( $requests ) - 1 ]['args'];
	}
}

if ( ! function_exists( 'slosm_cached' ) ) {
	/**
	 * Whether anything at all is stored under a key.
	 *
	 * The whole point of this helper: array_key_exists(), never get_transient().
	 * See the file header.
	 *
	 * @param string $key Transient key.
	 * @return bool
	 */
	function slosm_cached( string $key ): bool {
		return array_key_exists( $key, $GLOBALS['slosm_stub']['transients'] );
	}
}

if ( ! function_exists( 'slosm_assert_success_is_cached' ) ) {
	/**
	 * The control every "does not cache a failure" case runs.
	 *
	 * Such a case passes trivially on a geocoder that caches nothing at all —
	 * which is exactly how six of them passed against an empty class while this
	 * file was being written. So each one also looks up something that succeeds,
	 * through the same code, and insists that one was cached. Without this the
	 * assertion reads "nothing was written", which is true of a broken cache and
	 * of a correct refusal alike.
	 *
	 * The control query is deliberately a different string, so it cannot be
	 * served by, or collide with, the entry the case is asserting is absent.
	 *
	 * @param Geocoder $geocoder Geocoder under test, so the control shares its settings.
	 * @param string   $service  Service the case is about.
	 * @return void
	 */
	function slosm_assert_success_is_cached( Geocoder $geocoder, string $service = Geocoder::SERVICE_NOMINATIM ): void {
		if ( Geocoder::SERVICE_PHOTON === $service ) {
			slosm_queue_photon( array( slosm_photon_feature() ) );
			$geocoder->suggest( 'control query' );
		} else {
			slosm_queue_nominatim( array( slosm_nominatim_hit() ) );
			$geocoder->geocode( 'control query' );
		}

		assert_true(
			slosm_cached( $geocoder->cache_key( $service, 'control query' ) ),
			'the control lookup was not cached either, so this case proves nothing about the failure'
		);
	}
}

if ( ! function_exists( 'slosm_assert_the_limiter_exists' ) ) {
	/**
	 * The control every "does not wait" case runs.
	 *
	 * Same problem as the caching control, from the other side: "did not sleep"
	 * is what a geocoder with no rate limiter at all does, every time. So a case
	 * that expects no wait makes one more immediate request and insists that one
	 * did wait.
	 *
	 * @param Geocoder $geocoder Geocoder under test.
	 * @return void
	 */
	function slosm_assert_the_limiter_exists( Geocoder $geocoder ): void {
		$before = count( $GLOBALS['slosm_stub']['sleeps'] );

		slosm_queue_nominatim( array( slosm_nominatim_hit() ) );
		$geocoder->geocode( 'control query' );

		assert_same(
			$before + 1,
			count( $GLOBALS['slosm_stub']['sleeps'] ),
			'an immediate second request did not wait either, so there is no limiter for this case to be an exception to'
		);
	}
}

if ( ! function_exists( 'slosm_error_code' ) ) {
	/**
	 * The error code of a value, or a description of what it was instead.
	 *
	 * Returning a string either way keeps the failure message useful: a case
	 * that expected an error and got an array says so, instead of dying on a
	 * method call against an array.
	 *
	 * @param mixed $value Value returned by the geocoder.
	 * @return string
	 */
	function slosm_error_code( $value ): string {
		return is_wp_error( $value ) ? $value->get_error_code() : 'not a WP_Error: ' . var_export( $value, true );
	}
}

describe(
	'geocoder normalisation',
	function () {

		it(
			'shares one cache entry between a padded query and a lowercase one',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$first    = $geocoder->geocode( '  Warszawa  ' );
				$second   = $geocoder->geocode( 'warszawa' );

				assert_same( 1, slosm_requests(), 'the second lookup reached the network' );
				assert_same( $first, $second );
			}
		);

		it(
			'collapses a run of internal whitespace',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( "new\t  york" );
				$geocoder->geocode( 'New York' );

				assert_same( 1, slosm_requests(), 'the second lookup reached the network' );
			}
		);

		it(
			'collapses a non-breaking space the same way',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				// U+00A0, which arrives in pasted addresses. It collapses only
				// because the pattern carries /u: php_pcre.c sets PCRE2_UCP
				// alongside PCRE2_UTF, which is what widens \s past ascii. This
				// case is the pin on that — drop the modifier and it fails.
				$geocoder->geocode( "new\xC2\xA0york" );
				$geocoder->geocode( 'new york' );

				assert_same( 1, slosm_requests(), 'the second lookup reached the network' );
			}
		);

		it(
			'survives a query that is not valid utf-8',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				// preg_replace() with /u returns null on a malformed subject, so
				// an unguarded normaliser turns the whole query into null here
				// and looks up the empty string.
				$result = slosm_geocoder()->geocode( "warszawa \xC3\x28" );

				assert_true( is_array( $result ), 'expected a point, got ' . slosm_error_code( $result ) );
				assert_same( 1, slosm_requests() );
			}
		);

		it(
			'still collapses whitespace in a query that is not valid utf-8',
			function () {
				// Returning the query untouched when /u fails would pass the
				// case above and still leave two cache entries for one address,
				// so the ascii fallback needs a case that can only be satisfied
				// by collapsing.
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( "warszawa    \xC3\x28" );
				$geocoder->geocode( "warszawa \xC3\x28" );

				assert_same( 1, slosm_requests(), 'the second lookup reached the network' );
			}
		);

		it(
			'url-encodes the query it puts on the wire',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Kraków & Nowa Huta' );

				// A space, an ampersand and a diacritic. The ampersand is the
				// one that matters: unencoded it ends the q parameter and turns
				// the rest of the address into a parameter of its own.
				assert_contains( 'q=krak%C3%B3w%20%26%20nowa%20huta', slosm_last_url() );
			}
		);

		it(
			'lowercases the query it puts on the wire, so the wire and the key agree',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'WARSZAWA' );

				assert_contains( 'q=warszawa', slosm_last_url() );
			}
		);

		it(
			'refuses an empty query without making a request',
			function () {
				$result = slosm_geocoder()->geocode( "  \t " );

				assert_same( 'slosm_geocode_empty_query', slosm_error_code( $result ) );
				assert_same( 0, slosm_requests() );
			}
		);

		it(
			'normalises the country code',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'warszawa', ' PL ' );
				$geocoder->geocode( 'warszawa', 'pl' );

				assert_same( 1, slosm_requests(), 'the second lookup reached the network' );
				assert_contains( 'countrycodes=pl', slosm_last_url() );
			}
		);

		it(
			'keeps the cache key short enough for an option name',
			function () {
				// wp_options.option_name is varchar(191) — verified in
				// wp-admin/includes/schema.php of WordPress 6.9.1 — and a
				// transient adds the 19-character '_transient_timeout_' prefix,
				// so anything over 172 characters is silently truncated and two
				// long addresses collide.
				$key = slosm_geocoder()->cache_key( Geocoder::SERVICE_NOMINATIM, str_repeat( 'a very long street name ', 40 ) );

				// The lower bound is not decoration: "short enough" is satisfied
				// by returning nothing at all, and a key of '' would collide
				// with every other lookup on the site.
				assert_contains( 'slosm_geo', $key );
				assert_true( strlen( $key ) > 20, 'key is ' . strlen( $key ) . ' characters' );
				assert_true( strlen( $key ) <= 172, 'key is ' . strlen( $key ) . ' characters' );
			}
		);
	}
);

describe(
	'geocoder nominatim parsing',
	function () {

		it(
			'parses a Nominatim result',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$result = slosm_geocoder()->geocode( 'Warszawa' );

				assert_true( is_array( $result ), 'expected a point, got ' . slosm_error_code( $result ) );
				assert_close( 52.2297, $result['lat'], 0.0001 );
				assert_close( 21.0122, $result['lng'], 0.0001 );
				assert_same( 'Warszawa, Polska', $result['label'] );
			}
		);

		it(
			'reads lon rather than lng, and returns floats rather than the strings it was sent',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$result = slosm_geocoder()->geocode( 'Warszawa' );

				assert_true( is_float( $result['lat'] ), 'lat is ' . gettype( $result['lat'] ) );
				assert_true( is_float( $result['lng'] ), 'lng is ' . gettype( $result['lng'] ) );
			}
		);

		it(
			'refuses a latitude that is not a number rather than calling it zero',
			function () {
				// 0.0 is not "no coordinate", it is a point in the Gulf of
				// Guinea, and a float cast is how a location ends up there.
				slosm_queue_nominatim( array( slosm_nominatim_hit( 'not a number' ) ) );

				$result = slosm_geocoder()->geocode( 'Warszawa' );

				assert_same( 'slosm_geocode_bad_coordinates', slosm_error_code( $result ) );
			}
		);

		it(
			'refuses a longitude that is not a number',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit( '52.2297', '' ) ) );

				$result = slosm_geocoder()->geocode( 'Warszawa' );

				assert_same( 'slosm_geocode_bad_coordinates', slosm_error_code( $result ) );
			}
		);

		it(
			'refuses a coordinate that is off the earth',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit( '991.0' ) ) );

				$result = slosm_geocoder()->geocode( 'Warszawa' );

				assert_same( 'slosm_geocode_bad_coordinates', slosm_error_code( $result ) );
			}
		);

		it(
			'accepts a coordinate of exactly zero, which is a real place',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit( '0', '0', 'Null Island' ) ) );

				$result = slosm_geocoder()->geocode( 'null island' );

				assert_true( is_array( $result ), 'expected a point, got ' . slosm_error_code( $result ) );
				assert_close( 0.0, $result['lat'], 0.0001 );
			}
		);

		it(
			'falls back to the query when the label is there but blank',
			function () {
				// A present-but-empty display_name is not an absent one, and an
				// isset() guard alone hands the visitor a blank result row.
				slosm_queue_nominatim( array( slosm_nominatim_hit( '52.2297', '21.0122', '   ' ) ) );

				$result = slosm_geocoder()->geocode( 'Warszawa Centrum' );

				assert_same( 'Warszawa Centrum', $result['label'] );
			}
		);

		it(
			'falls back to the query when the result carries no label',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit( '52.2297', '21.0122', null ) ) );

				// The collapsed query, not the lowercased one: this string is
				// shown to a person.
				$result = slosm_geocoder()->geocode( '  Warszawa   Centrum  ' );

				assert_same( 'Warszawa Centrum', $result['label'] );
			}
		);

		it(
			'takes the first result when the endpoint ignores the limit',
			function () {
				slosm_queue_nominatim(
					array(
						slosm_nominatim_hit( '52.2297', '21.0122', 'Warszawa, Polska' ),
						slosm_nominatim_hit( '50.0647', '19.9450', 'Kraków, Polska' ),
					)
				);

				$result = slosm_geocoder()->geocode( 'Warszawa' );

				assert_same( 'Warszawa, Polska', $result['label'] );
			}
		);

		it(
			'returns a WP_Error on an empty result set',
			function () {
				slosm_queue_response( '[]' );

				$result = slosm_geocoder()->geocode( 'nowhere at all' );

				assert_same( 'slosm_geocode_no_results', slosm_error_code( $result ) );
			}
		);

		it(
			'returns a WP_Error on a 200 carrying malformed json',
			function () {
				slosm_queue_response( '{"lat": 52.2297' );

				$result = slosm_geocoder()->geocode( 'Warszawa' );

				assert_same( 'slosm_geocode_bad_json', slosm_error_code( $result ) );
			}
		);

		it(
			'returns a WP_Error when the body is an object rather than a list',
			function () {
				// What Nominatim answers with when it rejects the request.
				slosm_queue_response( '{"error":"Unable to geocode"}' );

				$result = slosm_geocoder()->geocode( 'Warszawa' );

				assert_same( 'slosm_geocode_bad_json', slosm_error_code( $result ) );
			}
		);

		it(
			'returns a WP_Error when the body is a bare json string',
			function () {
				// json_decode() accepts this happily and hands back a string, so
				// a guard written as "did json_decode fail" lets it through.
				slosm_queue_response( '"try again later"' );

				$result = slosm_geocoder()->geocode( 'Warszawa' );

				assert_same( 'slosm_geocode_bad_json', slosm_error_code( $result ) );

				// The status comes with it, and the assertion is here for a
				// reason beyond completeness: a string subscripted by [0] is a
				// character, so a parser that let a non-array through would
				// reach the same error code from further downstream, with
				// nothing to say about what the service answered. This is what
				// tells the two apart.
				$data = $result->get_error_data();
				assert_same( 200, $data['upstream_status'] );
			}
		);

		it(
			'reports a transport failure as a transport failure, not as no matches',
			function () {
				// wp_remote_retrieve_body() on a WP_Error returns '', which
				// parses as no json at all. Code that skips is_wp_error() tells
				// the visitor "no matches" for what was a dns failure.
				$GLOBALS['slosm_stub']['http_queue'][] = new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );

				$result = slosm_geocoder()->geocode( 'Warszawa' );

				assert_same( 'slosm_geocode_http', slosm_error_code( $result ) );
				$data = $result->get_error_data();
				assert_contains( 'Could not resolve host', (string) $data['upstream_message'] );
			}
		);

		it(
			'gives HTTP 429 its own error code and carries the status',
			function () {
				slosm_queue_response( 'Too Many Requests', 429 );

				$result = slosm_geocoder()->geocode( 'Warszawa' );

				assert_same( 'slosm_geocode_rate_limited', slosm_error_code( $result ) );
				$data = $result->get_error_data();
				assert_same( 429, $data['upstream_status'] );
			}
		);

		it(
			'gives any other non-200 a different error code again',
			function () {
				slosm_queue_response( 'Bad Gateway', 502 );

				$result = slosm_geocoder()->geocode( 'Warszawa' );

				assert_same( 'slosm_geocode_bad_status', slosm_error_code( $result ) );
				$data = $result->get_error_data();
				assert_same( 502, $data['upstream_status'] );
			}
		);
	}
);

describe(
	'geocoder caching',
	function () {

		it(
			'does not reach the network for a repeat lookup',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );
				$geocoder->geocode( 'Warszawa' );

				assert_same( 1, slosm_requests() );
			}
		);

		it(
			'serves the repeat lookup to a second instance, not only to itself',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );
				$second = slosm_geocoder()->geocode( 'Warszawa' );

				assert_same( 1, slosm_requests() );
				assert_close( 52.2297, $second['lat'], 0.0001 );
			}
		);

		it(
			'caches a successful lookup for thirty days',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				$key = $geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Warszawa' );

				assert_true( slosm_cached( $key ), 'nothing was cached' );
				assert_same( 30 * DAY_IN_SECONDS, $GLOBALS['slosm_stub']['transients'][ $key ]['ttl'] );
			}
		);

		it(
			'reaches the network again once the lifetime has run out',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				slosm_stub_advance_time( 30 * DAY_IN_SECONDS + 1 );
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );
				$geocoder->geocode( 'Warszawa' );

				assert_same( 2, slosm_requests() );
			}
		);

		it(
			'does not cache a rate-limited response',
			function () {
				slosm_queue_response( 'Too Many Requests', 429 );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				// array_key_exists, not get_transient: see the file header.
				// Caching this for thirty days breaks geocoding for a month.
				assert_false( slosm_cached( $geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Warszawa' ) ) );
				slosm_assert_success_is_cached( $geocoder );
			}
		);

		it(
			'reaches the network again after a rate-limited response',
			function () {
				slosm_queue_response( 'Too Many Requests', 429 );
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );
				$result = $geocoder->geocode( 'Warszawa' );

				assert_same( 2, slosm_requests() );
				assert_true( is_array( $result ), 'expected a point, got ' . slosm_error_code( $result ) );
			}
		);

		it(
			'does not cache an empty result set',
			function () {
				slosm_queue_response( '[]' );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'nowhere at all' );

				assert_false( slosm_cached( $geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'nowhere at all' ) ) );
				slosm_assert_success_is_cached( $geocoder );
			}
		);

		it(
			'does not cache a transport failure',
			function () {
				$GLOBALS['slosm_stub']['http_queue'][] = new WP_Error( 'http_request_failed', 'Could not resolve host' );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				assert_false( slosm_cached( $geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Warszawa' ) ) );
				slosm_assert_success_is_cached( $geocoder );
			}
		);

		it(
			'does not cache malformed json',
			function () {
				slosm_queue_response( '{"lat": 52.2297' );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				assert_false( slosm_cached( $geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Warszawa' ) ) );
				slosm_assert_success_is_cached( $geocoder );
			}
		);

		it(
			'does not cache a result whose coordinates could not be read',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit( 'not a number' ) ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				assert_false( slosm_cached( $geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Warszawa' ) ) );
				slosm_assert_success_is_cached( $geocoder );
			}
		);

		it(
			'keeps two countries in two cache entries',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );
				slosm_queue_nominatim( array( slosm_nominatim_hit( '52.5200', '13.4050', 'Berlin, Deutschland' ) ) );

				$geocoder = slosm_geocoder();
				$polish   = $geocoder->geocode( 'warszawa', 'pl' );
				$german   = $geocoder->geocode( 'warszawa', 'de' );

				assert_same( 2, slosm_requests() );
				assert_same( 'Berlin, Deutschland', $german['label'] );
				assert_same( 'Warszawa, Polska', $polish['label'] );
			}
		);

		it(
			'ignores a cached value of the wrong shape rather than returning it',
			function () {
				$geocoder = slosm_geocoder();
				$key      = $geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Warszawa' );

				set_transient( $key, 'this is not a point', 60 );
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$result = $geocoder->geocode( 'Warszawa' );

				assert_true( is_array( $result ), 'expected a point, got ' . slosm_error_code( $result ) );
				assert_same( 1, slosm_requests() );
			}
		);

		it(
			'reports a cache write it could not make',
			function () {
				$GLOBALS['slosm_stub']['transient_failure'] = true;
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$fired = array();
				add_action(
					'slosm_geocoder_cache_write_failed',
					static function ( $key, $service ) use ( &$fired ) {
						$fired[] = $key . '|' . $service;
					}
				);

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				assert_same( 1, count( $fired ) );
				assert_same(
					$geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Warszawa' ) . '|' . Geocoder::SERVICE_NOMINATIM,
					$fired[0]
				);
			}
		);

		it(
			'names the plugin, the shape version and the generation in the key',
			function () {
				$key = slosm_geocoder()->cache_key( Geocoder::SERVICE_NOMINATIM, 'Warszawa' );

				assert_contains( 'slosm_geo_nominatim_v1_g0_', $key );
			}
		);

		it(
			'moves every cached lookup out of reach when the cache is flushed',
			function () {
				// The reach that matters is the one a wp_options sweep does not
				// have: a site with a persistent object cache keeps its
				// transients somewhere no SQL can see, so invalidation has to be
				// a key change rather than a delete.
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );
				$geocoder->flush_cache();

				slosm_queue_nominatim( array( slosm_nominatim_hit( '50.0647', '19.9450', 'Kraków, Polska' ) ) );
				$after = $geocoder->geocode( 'Warszawa' );

				assert_same( 2, slosm_requests(), 'the flushed lookup was still served from the cache' );
				assert_same( 'Kraków, Polska', $after['label'] );
			}
		);

		it(
			'says so when the cache could not be flushed',
			function () {
				$GLOBALS['slosm_stub']['option_failure'] = true;

				$fired = array();
				add_action(
					'slosm_geocoder_flush_failed',
					static function ( $option, $generation ) use ( &$fired ) {
						$fired[] = $option . '|' . $generation;
					}
				);

				slosm_geocoder()->flush_cache();

				assert_same( 1, count( $fired ) );
				assert_same( Geocoder::GENERATION_OPTION . '|1', $fired[0] );
			}
		);

		it(
			'keeps the generation out of alloptions too',
			function () {
				slosm_geocoder()->flush_cache();

				assert_false( $GLOBALS['slosm_stub']['option_autoload'][ Geocoder::GENERATION_OPTION ] );
			}
		);

		it(
			'reads a generation left behind as rubbish as zero, not as an empty segment',
			function () {
				// Concatenated raw, false or 'nonsense' collapses the segment and
				// gives one key for two generations — an invalidation that stops
				// working and says nothing.
				$GLOBALS['slosm_stub']['options'][ Geocoder::GENERATION_OPTION ] = 'nonsense';

				assert_contains( '_g0_', slosm_geocoder()->cache_key( Geocoder::SERVICE_NOMINATIM, 'Warszawa' ) );
			}
		);

		it(
			'caches nothing when the lifetime is configured to zero',
			function () {
				// WordPress reads a zero expiry as "never expires", which for a
				// geocode is the opposite of what a site turning the cache off
				// meant. Zero has to mean "do not write".
				$GLOBALS['slosm_stub']['options']['slosm_settings'] = array( 'geocode_cache_ttl' => 0 );
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );
				$geocoder->geocode( 'Warszawa' );

				assert_false( slosm_cached( $geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Warszawa' ) ) );
				assert_same( 2, slosm_requests() );
			}
		);
	}
);

describe(
	'geocoder rate limit',
	function () {

		it(
			'does not wait before the first request a site makes',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				assert_same( 1, slosm_requests() );
				assert_same( array(), $GLOBALS['slosm_stub']['sleeps'] );
				slosm_assert_the_limiter_exists( $geocoder );
			}
		);

		it(
			'waits a second between two requests',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );
				slosm_queue_nominatim( array( slosm_nominatim_hit( '50.0647', '19.9450', 'Kraków' ) ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );
				$geocoder->geocode( 'Kraków' );

				$sleeps = $GLOBALS['slosm_stub']['sleeps'];

				assert_same( 1, count( $sleeps ), 'sleeps: ' . var_export( $sleeps, true ) );
				assert_close( 1.0, $sleeps[0], 0.0001 );
			}
		);

		it(
			'waits only the remainder when part of the second has already passed',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );
				slosm_queue_nominatim( array( slosm_nominatim_hit( '50.0647', '19.9450', 'Kraków' ) ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );
				slosm_stub_advance_time( 0.25 );
				$geocoder->geocode( 'Kraków' );

				$sleeps = $GLOBALS['slosm_stub']['sleeps'];

				assert_same( 1, count( $sleeps ), 'sleeps: ' . var_export( $sleeps, true ) );
				assert_close( 0.75, $sleeps[0], 0.0001 );
			}
		);

		it(
			'does not wait when a second has already passed',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );
				slosm_queue_nominatim( array( slosm_nominatim_hit( '50.0647', '19.9450', 'Kraków' ) ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );
				slosm_stub_advance_time( 1.5 );
				$geocoder->geocode( 'Kraków' );

				assert_same( array(), $GLOBALS['slosm_stub']['sleeps'] );
				slosm_assert_the_limiter_exists( $geocoder );
			}
		);

		it(
			'shares the limiter between two instances, because two requests are two processes',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );
				slosm_queue_nominatim( array( slosm_nominatim_hit( '50.0647', '19.9450', 'Kraków' ) ) );

				// A limiter held only in an instance property lets every php
				// process on the site fire at once, which is the abuse the
				// policy is about. Two instances stand in for two requests.
				slosm_geocoder()->geocode( 'Warszawa' );
				slosm_geocoder()->geocode( 'Kraków' );

				$sleeps = $GLOBALS['slosm_stub']['sleeps'];

				assert_same( 1, count( $sleeps ), 'sleeps: ' . var_export( $sleeps, true ) );
				assert_close( 1.0, $sleeps[0], 0.0001 );
			}
		);

		it(
			'does not wait a century for a stored timestamp from the future',
			function () {
				// A restored database, a clock that jumped, or another plugin on
				// the same option name. Unclamped, the remainder of a second is
				// a billion seconds and the request never returns.
				$GLOBALS['slosm_stub']['options'][ slosm_geocoder()->last_request_option( Geocoder::SERVICE_NOMINATIM ) ] = slosm_stub_time() + 1000000000.0;
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				$sleeps = $GLOBALS['slosm_stub']['sleeps'];

				assert_same( 1, count( $sleeps ), 'sleeps: ' . var_export( $sleeps, true ) );
				assert_close( 1.0, $sleeps[0], 0.0001 );
			}
		);

		it(
			'ignores a stored timestamp that is not a number',
			function () {
				// Not 'yesterday': (float) 'yesterday' is 0.0, which reads as "never
				// asked" whether or not the is_numeric() check is there, so the case
				// would pass either way. This casts to a real recent timestamp, so a
				// version without the check would sleep.
				$GLOBALS['slosm_stub']['options'][ slosm_geocoder()->last_request_option( Geocoder::SERVICE_NOMINATIM ) ] = '1767225600 (utc)';
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				assert_same( array(), $GLOBALS['slosm_stub']['sleeps'] );
				slosm_assert_the_limiter_exists( $geocoder );
			}
		);

		it(
			'still limits itself within one process when the option store is failing',
			function () {
				$GLOBALS['slosm_stub']['option_failure'] = true;
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );
				slosm_queue_nominatim( array( slosm_nominatim_hit( '50.0647', '19.9450', 'Kraków' ) ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );
				$geocoder->geocode( 'Kraków' );

				$sleeps = $GLOBALS['slosm_stub']['sleeps'];

				assert_same( 1, count( $sleeps ), 'sleeps: ' . var_export( $sleeps, true ) );
				assert_close( 1.0, $sleeps[0], 0.0001 );
			}
		);

		it(
			'reports a limiter state it could not write',
			function () {
				$GLOBALS['slosm_stub']['option_failure'] = true;
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$fired = array();
				add_action(
					'slosm_geocoder_throttle_write_failed',
					static function ( $option, $timestamp ) use ( &$fired ) {
						$fired[] = $option;
					}
				);

				slosm_geocoder()->geocode( 'Warszawa' );

				assert_same( 1, count( $fired ) );
				assert_same( slosm_geocoder()->last_request_option( Geocoder::SERVICE_NOMINATIM ), $fired[0] );
			}
		);

		it(
			'records when it last asked, so the next request can measure the gap',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				$option = $geocoder->last_request_option( Geocoder::SERVICE_NOMINATIM );

				assert_close( slosm_stub_time(), (float) $GLOBALS['slosm_stub']['options'][ $option ], 0.0001 );
			}
		);

		it(
			'keeps its timestamp out of alloptions',
			function () {
				// Autoloaded, this would be fetched on every request on the site
				// — including the overwhelming majority that never geocode
				// anything.
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				$option = $geocoder->last_request_option( Geocoder::SERVICE_NOMINATIM );

				assert_false( $GLOBALS['slosm_stub']['option_autoload'][ $option ] );
			}
		);

		it(
			'neither requests nor waits for a cached lookup',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				$GLOBALS['slosm_stub']['sleeps'] = array();
				$geocoder->geocode( 'Warszawa' );

				assert_same( 1, slosm_requests() );
				assert_same( array(), $GLOBALS['slosm_stub']['sleeps'] );
			}
		);

		it(
			'keeps a separate limiter per service',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );
				slosm_queue_photon( array( slosm_photon_feature() ) );

				// Different hosts with different policies. Throttling
				// autocomplete because a bulk geocode run is in progress would
				// make the search box feel broken to protect a limit that is
				// not Photon's.
				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );
				$geocoder->suggest( 'Warszawa' );

				assert_same( 2, slosm_requests() );
				assert_same( array(), $GLOBALS['slosm_stub']['sleeps'] );
				slosm_assert_the_limiter_exists( $geocoder );
			}
		);

		/*
		 * would_throttle() below is the limiter's read half asked as a question,
		 * for a caller that would rather skip than wait — Task 10's /suggest.
		 * Every case here is about it agreeing with throttle(), because the one
		 * failure that matters is the two coming apart: a predicate that says
		 * "free" where the limiter then sleeps is a worker held by the very code
		 * that was meant to protect it, and a predicate that says "busy" where
		 * the limiter would have fired is an autocomplete that answers nothing
		 * forever. They share last_request_at(), and these cases are what keeps
		 * that true.
		 */

		it(
			'says a service is free before a site has asked it anything',
			function () {
				assert_false( slosm_geocoder()->would_throttle( Geocoder::SERVICE_NOMINATIM ) );
			}
		);

		it(
			'says a service is busy for exactly as long as the limiter would wait',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				assert_true( $geocoder->would_throttle( Geocoder::SERVICE_NOMINATIM ) );

				// A hair under the interval: still busy, and throttle() would
				// still sleep.
				slosm_stub_advance_time( Geocoder::MIN_INTERVAL - 0.01 );

				assert_true( $geocoder->would_throttle( Geocoder::SERVICE_NOMINATIM ) );

				slosm_stub_advance_time( 0.02 );

				assert_false( $geocoder->would_throttle( Geocoder::SERVICE_NOMINATIM ) );
			}
		);

		it(
			'asks and answers about one service at a time',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				assert_true( $geocoder->would_throttle( Geocoder::SERVICE_NOMINATIM ) );
				assert_false( $geocoder->would_throttle( Geocoder::SERVICE_PHOTON ) );
			}
		);

		it(
			'reads a stored timestamp that is not a number as never asked',
			function () {
				$geocoder = slosm_geocoder();
				$option   = $geocoder->last_request_option( Geocoder::SERVICE_PHOTON );

				// Not 'yesterday', and the choice is the whole case. A cast of
				// 'yesterday' is 0.0, which reads as "never asked" anyway — so a
				// version with the is_numeric() check deleted answers 'yesterday'
				// exactly as the checked one does, and a case built on it cannot
				// fail. This value can only be told apart by the check: a
				// timestamp with a suffix on it, which is what a botched
				// migration or another plugin annotating the same option name
				// leaves behind. is_numeric() says no and the answer is "never
				// asked"; a bare cast says now and the answer would be "busy",
				// which would strand every suggestion on the site.
				update_option( $option, (string) slosm_stub_time() . ' (utc)', false );

				assert_false( $geocoder->would_throttle( Geocoder::SERVICE_PHOTON ) );

				// The control: the same number without the suffix does read as
				// busy, so the false above is about the value rather than about
				// the option being the wrong one.
				update_option( $option, slosm_stub_time(), false );

				assert_true( $geocoder->would_throttle( Geocoder::SERVICE_PHOTON ) );
			}
		);

		it(
			'treats a timestamp from the future as busy, because the limiter would sleep on it',
			function () {
				$geocoder = slosm_geocoder();

				// A restored database or a clock that jumped. throttle() clamps
				// such a value to a whole interval's wait rather than firing, so
				// a predicate calling it free would promise a request that then
				// blocked for a second.
				update_option(
					$geocoder->last_request_option( Geocoder::SERVICE_PHOTON ),
					slosm_stub_time() + 3600.0,
					false
				);

				assert_true( $geocoder->would_throttle( Geocoder::SERVICE_PHOTON ) );
			}
		);

		it(
			'sees the in-process timestamp the limiter falls back on when the option store is failing',
			function () {
				// The divergence this method exists to prevent. With
				// update_option() failing, the option stays empty and only the
				// per-instance timestamp records the request — so a predicate
				// reading the option alone would call the service free while
				// throttle() slept on exactly the same state.
				$GLOBALS['slosm_stub']['option_failure'] = true;

				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				$option = $geocoder->last_request_option( Geocoder::SERVICE_NOMINATIM );

				// The control: nothing was written, so the option cannot be what
				// the answer below comes from.
				assert_false( array_key_exists( $option, $GLOBALS['slosm_stub']['options'] ) );

				assert_true( $geocoder->would_throttle( Geocoder::SERVICE_NOMINATIM ) );

				// And a second instance, which shares no memory with the first,
				// correctly sees nothing — that is the limitation being narrowed
				// here, not a guarantee.
				assert_false( slosm_geocoder()->would_throttle( Geocoder::SERVICE_NOMINATIM ) );
			}
		);

		it(
			'neither asks nor sleeps to answer the question',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();

				// The queued response must still be queued: wp_remote_get()
				// throws when nothing is queued, so a full queue is what makes
				// "no request" a positive fact.
				$geocoder->would_throttle( Geocoder::SERVICE_NOMINATIM );
				$geocoder->would_throttle( Geocoder::SERVICE_PHOTON );

				assert_same( 0, slosm_requests() );
				assert_same( array(), $GLOBALS['slosm_stub']['sleeps'] );
				assert_same( 1, count( $GLOBALS['slosm_stub']['http_queue'] ) );
				assert_same( array(), $GLOBALS['slosm_stub']['options'] );
			}
		);
	}
);

describe(
	'geocoder production clock and sleeper',
	function () {

		/*
		 * The only cases in this file that touch real time, and they are here
		 * because everything else in it deliberately does not: the limiter is
		 * proved against injected recorders, which leaves the defaults — the
		 * code that actually runs on a site — covered by nothing at all. Three
		 * mutations survived the other 223 cases, and the worst of them is the
		 * one the constructor's own docblock names: usleep() takes microseconds,
		 * the seam is defined in seconds, and dropping the conversion turns one
		 * second of courtesy into one microsecond. The limiter would still be
		 * there, still be tested, and still be gone in production.
		 *
		 * Neither case can be flaky in the direction that matters. Both assert a
		 * lower bound on elapsed time with a wide upper bound above it, so a
		 * slow or descheduled machine passes; what fails is time not passing at
		 * all, which is what every one of these mutations produces.
		 *
		 * The defaults are read with reflection rather than exposed through a
		 * getter. A getter would exist only for the test, and the seam a caller
		 * is supposed to use is the constructor.
		 */

		it(
			'defaults to a clock that measures below a second',
			function () {
				$clock = ( new ReflectionClass( 'Asymetria\StoreLocator\Geocoder' ) )->getProperty( 'clock' );
				$default = $clock->getValue( new Asymetria\StoreLocator\Geocoder() );

				$before = $default();
				usleep( 5000 );
				$after = $default();

				assert_true( is_float( $before ), 'the clock returned ' . gettype( $before ) );

				// A (float) time() clock reads the same on both sides of a five
				// millisecond sleep, or jumps a whole second across a boundary.
				// Neither lands in this window; microtime( true ) always does.
				$elapsed = $after - $before;

				assert_true( $elapsed > 0.0, 'the clock did not move across a real sleep' );
				assert_true( $elapsed < 0.5, 'the clock moved ' . $elapsed . ' seconds, which is not sub-second resolution' );
			}
		);

		it(
			'defaults to a sleeper that reads its argument as seconds',
			function () {
				$sleeper = ( new ReflectionClass( 'Asymetria\StoreLocator\Geocoder' ) )->getProperty( 'sleeper' );
				$default = $sleeper->getValue( new Asymetria\StoreLocator\Geocoder() );

				$before = hrtime( true );
				$default( 0.01 );
				$elapsed_ns = hrtime( true ) - $before;

				// Ten milliseconds asked for. Read as microseconds it would be
				// ten of them, and the whole call would return in well under a
				// millisecond.
				assert_true(
					$elapsed_ns > 1000000,
					'the sleeper returned after ' . ( $elapsed_ns / 1000000 ) . ' ms, so it is not treating its argument as seconds'
				);
			}
		);

		it(
			'defaults to a sleeper that declines to sleep a negative interval',
			function () {
				// usleep() raises a ValueError on a negative argument in PHP 8,
				// so the guard is the difference between "no wait needed" and a
				// fatal in the middle of a geocode.
				$sleeper = ( new ReflectionClass( 'Asymetria\StoreLocator\Geocoder' ) )->getProperty( 'sleeper' );
				$default = $sleeper->getValue( new Asymetria\StoreLocator\Geocoder() );

				$before = hrtime( true );
				$default( 0.0 );
				$default( -1.0 );
				$elapsed_ns = hrtime( true ) - $before;

				assert_true( $elapsed_ns < 1000000000, 'a non-positive interval was slept anyway' );
			}
		);
	}
);

describe(
	'geocoder request',
	function () {

		it(
			'sends a User-Agent identifying the plugin and the site',
			function () {
				$GLOBALS['slosm_stub']['options']['home'] = 'https://sklep.example.test';
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				$agent = (string) slosm_last_args()['headers']['User-Agent'];

				assert_true( '' !== $agent, 'the User-Agent header was empty' );
				assert_contains( 'Store Locator for OpenStreetMap', $agent );
				assert_contains( 'sklep.example.test', $agent );

				// The version too, which this case could not assert until
				// tests/bootstrap.php started defining SLOSM_VERSION: without
				// it, the constant was undefined here and the header read
				// ".../unknown", which the two assertions above accept. The
				// Nominatim policy asks for an application that identifies
				// itself, and a version is half of identifying a build.
				assert_contains( SLOSM_VERSION, $agent );
			}
		);

		it(
			'sends the same agent as the request argument, because either can win',
			function () {
				// Measured against WordPress 6.9.1: WP_Http hands 'user-agent'
				// to Requests as the useragent option, and
				// Requests/src/Transport/Fsockopen.php only emits it when no
				// User-Agent header is present. Setting one and not the other
				// therefore depends on which transport the host has.
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				$args = slosm_last_args();

				assert_same( 1, slosm_requests() );
				assert_contains( 'Store Locator for OpenStreetMap', (string) $args['user-agent'] );
				assert_same( $args['headers']['User-Agent'], $args['user-agent'] );
			}
		);

		it(
			'strips newlines out of a configured agent',
			function () {
				$GLOBALS['slosm_stub']['options']['slosm_settings'] = array( 'geocode_user_agent' => "MyAgent/1.0\r\nX-Injected: yes" );
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				$agent = (string) slosm_last_args()['headers']['User-Agent'];

				assert_contains( 'MyAgent/1.0', $agent );
				assert_same( false, strpos( $agent, "\n" ) );
				assert_same( false, strpos( $agent, "\r" ) );
			}
		);

		it(
			'asks for a bounded timeout',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				assert_same( Geocoder::HTTP_TIMEOUT, slosm_last_args()['timeout'] );
			}
		);

		it(
			'asks Nominatim for one result in json',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				assert_contains( 'format=jsonv2', slosm_last_url() );
				assert_contains( 'limit=1', slosm_last_url() );
			}
		);

		it(
			'sends no countrycodes when no country was asked for',
			function () {
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				assert_contains( 'q=warszawa', slosm_last_url() );
				assert_same( false, strpos( slosm_last_url(), 'countrycodes' ) );
			}
		);

		it(
			'registers no hooks of its own',
			function () {
				// Task 10 wires this class. A geocoder that hooked itself on
				// construction would register its callbacks again for every
				// instance anything builds, including the ones a test builds.
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				assert_same( 1, slosm_requests() );
				assert_same( array(), $GLOBALS['slosm_stub']['actions'] );
				assert_same( array(), $GLOBALS['slosm_stub']['filters'] );
			}
		);
	}
);

describe(
	'geocoder configuration',
	function () {

		it(
			'reads the endpoint from settings',
			function () {
				$GLOBALS['slosm_stub']['options']['slosm_settings'] = array( 'geocode_endpoint' => 'https://nominatim.example.test/search' );
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				assert_contains( 'https://nominatim.example.test/search?', slosm_last_url() );
			}
		);

		it(
			'lets a filter override the endpoint',
			function () {
				add_filter(
					'slosm_geocoder_endpoint',
					static function ( $url, $service ) {
						return Geocoder::SERVICE_NOMINATIM === $service ? 'https://filtered.example.test/search' : $url;
					}
				);
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				assert_contains( 'https://filtered.example.test/search?', slosm_last_url() );
			}
		);

		it(
			'keeps a query string a configured endpoint already carries',
			function () {
				// A paid provider's url often carries an api key.
				$GLOBALS['slosm_stub']['options']['slosm_settings'] = array( 'geocode_endpoint' => 'https://geo.example.test/search?key=abc123' );
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				assert_contains( 'key=abc123&', slosm_last_url() );
			}
		);

		it(
			'falls back to the default endpoint when the configured one is not http',
			function () {
				/*
				 * ftp:// rather than javascript:, and the difference is the
				 * whole case. Both are useless as an endpoint, but only one of
				 * them survives WordPress: esc_url_raw() with no protocol list
				 * means wp_allowed_protocols(), which has ftp, telnet, svn and
				 * nineteen more in it. A javascript: fixture would pass against
				 * code that passed no list at all — and did, until the stub was
				 * corrected to match core. What happens to a site that gets
				 * here is not a fallback but a permanent transport error, since
				 * Requests answers an ftp url with "Only HTTP(S) requests are
				 * handled".
				 */
				$GLOBALS['slosm_stub']['options']['slosm_settings'] = array( 'geocode_endpoint' => 'ftp://geo.example.test/search' );
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				assert_contains( 'nominatim.openstreetmap.org', slosm_last_url() );
			}
		);

		it(
			'falls back when a filter returns an endpoint that is not http',
			function () {
				// The filtered value goes through the same sanitisation as the
				// setting. Nothing covered this path at all.
				add_filter(
					'slosm_geocoder_endpoint',
					static function ( $url, $service ) {
						return 'ftp://filtered.example.test/search';
					}
				);
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				assert_contains( 'nominatim.openstreetmap.org', slosm_last_url() );
			}
		);

		it(
			'keeps the configured endpoint when a filter answers with rubbish',
			function () {
				// Falling all the way back to the default would silently discard
				// a setting the site meant.
				$GLOBALS['slosm_stub']['options']['slosm_settings'] = array( 'geocode_endpoint' => 'https://mine.example.test/search' );
				add_filter(
					'slosm_geocoder_endpoint',
					static function ( $url, $service ) {
						return array( 'not', 'a', 'url' );
					}
				);
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				assert_contains( 'https://mine.example.test/search?', slosm_last_url() );
			}
		);

		it(
			'reads the User-Agent from settings',
			function () {
				$GLOBALS['slosm_stub']['options']['slosm_settings'] = array( 'geocode_user_agent' => 'Sklep Locator/2.0 (+https://sklep.example.test)' );
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				assert_same( 'Sklep Locator/2.0 (+https://sklep.example.test)', slosm_last_args()['headers']['User-Agent'] );
			}
		);

		it(
			'lets a filter override the User-Agent',
			function () {
				add_filter(
					'slosm_geocoder_user_agent',
					static function ( $agent, $service ) {
						return 'Filtered/1.0';
					}
				);
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				assert_same( 'Filtered/1.0', slosm_last_args()['headers']['User-Agent'] );
			}
		);

		it(
			'never sends an empty User-Agent, whatever a filter returns',
			function () {
				// Nominatim's policy is about being identifiable. An empty agent
				// is the one value that must not survive a filter.
				add_filter(
					'slosm_geocoder_user_agent',
					static function ( $agent, $service ) {
						return '';
					}
				);
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				slosm_geocoder()->geocode( 'Warszawa' );

				assert_contains( 'Store Locator for OpenStreetMap', (string) slosm_last_args()['headers']['User-Agent'] );
			}
		);

		it(
			'reads the cache lifetime from settings',
			function () {
				$GLOBALS['slosm_stub']['options']['slosm_settings'] = array( 'geocode_cache_ttl' => 3600 );
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				$key = $geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Warszawa' );

				assert_same( 3600, $GLOBALS['slosm_stub']['transients'][ $key ]['ttl'] );
			}
		);

		it(
			'lets a filter override the cache lifetime',
			function () {
				add_filter(
					'slosm_geocoder_cache_ttl',
					static function ( $ttl, $service ) {
						return Geocoder::SERVICE_NOMINATIM === $service ? 120 : $ttl;
					}
				);
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				$key = $geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Warszawa' );

				assert_same( 120, $GLOBALS['slosm_stub']['transients'][ $key ]['ttl'] );
			}
		);

		it(
			'treats a negative lifetime as no cache rather than as already expired',
			function () {
				// set_transient() treats a negative lifetime as "expired on
				// arrival", so a geocoder that forwarded it would write a row
				// that can never be read — the cost of a cache with none of the
				// benefit.
				$GLOBALS['slosm_stub']['options']['slosm_settings'] = array( 'geocode_cache_ttl' => -1 );
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );

				$geocoder = slosm_geocoder();
				$geocoder->geocode( 'Warszawa' );

				assert_false( slosm_cached( $geocoder->cache_key( Geocoder::SERVICE_NOMINATIM, 'Warszawa' ) ) );

				unset( $GLOBALS['slosm_stub']['options']['slosm_settings'] );
				slosm_assert_success_is_cached( $geocoder );
			}
		);
	}
);

describe(
	'geocoder suggest',
	function () {

		it(
			'parses a Photon feature collection',
			function () {
				slosm_queue_photon( array( slosm_photon_feature() ) );

				$result = slosm_geocoder()->suggest( 'Warszawa' );

				assert_true( is_array( $result ), 'expected a list, got ' . slosm_error_code( $result ) );
				assert_same( 1, count( $result ) );
				// [ lon, lat ] on the wire. Reading them in order puts Warsaw
				// off the coast of Somalia.
				assert_close( 52.2297, $result[0]['lat'], 0.0001 );
				assert_close( 21.0122, $result[0]['lng'], 0.0001 );
			}
		);

		it(
			'builds a label out of the Photon properties',
			function () {
				slosm_queue_photon(
					array(
						slosm_photon_feature(
							21.0122,
							52.2297,
							array(
								'name'        => 'Pałac Kultury',
								'street'      => 'Plac Defilad',
								'housenumber' => '1',
								'postcode'    => '00-901',
								'city'        => 'warszawa',
								'country'     => 'Polska',
							)
						),
					)
				);

				$result = slosm_geocoder()->suggest( 'palac' );

				assert_same( 'Pałac Kultury, Plac Defilad 1, 00-901, warszawa, Polska', $result[0]['label'] );
			}
		);

		it(
			'does not repeat a part of the label that is already in it',
			function () {
				slosm_queue_photon(
					array(
						slosm_photon_feature(
							21.0122,
							52.2297,
							array(
								'name'    => 'Warszawa',
								'city'    => 'Warszawa',
								'country' => 'Polska',
							)
						),
					)
				);

				$result = slosm_geocoder()->suggest( 'warszawa' );

				assert_same( 'Warszawa, Polska', $result[0]['label'] );
			}
		);

		it(
			'returns several suggestions in the order they arrived',
			function () {
				slosm_queue_photon(
					array(
						slosm_photon_feature( 21.0122, 52.2297, array( 'name' => 'Warszawa' ) ),
						slosm_photon_feature( 19.9450, 50.0647, array( 'name' => 'Kraków' ) ),
					)
				);

				$result = slosm_geocoder()->suggest( 'w' );

				assert_same( 2, count( $result ) );
				assert_same( 'Warszawa', $result[0]['label'] );
				assert_same( 'Kraków', $result[1]['label'] );
			}
		);

		it(
			'drops a feature whose coordinates are not numbers',
			function () {
				slosm_queue_photon(
					array(
						slosm_photon_feature( 'x', 'y', array( 'name' => 'Nowhere' ) ),
						slosm_photon_feature( 21.0122, 52.2297, array( 'name' => 'Warszawa' ) ),
					)
				);

				$result = slosm_geocoder()->suggest( 'w' );

				assert_same( 1, count( $result ) );
				assert_same( 'Warszawa', $result[0]['label'] );
			}
		);

		it(
			'drops a feature that would have no label to show',
			function () {
				slosm_queue_photon(
					array(
						slosm_photon_feature( 21.0122, 52.2297, array( 'osm_key' => 'place' ) ),
						slosm_photon_feature( 19.9450, 50.0647, array( 'name' => 'Kraków' ) ),
					)
				);

				$result = slosm_geocoder()->suggest( 'k' );

				assert_same( 1, count( $result ) );
				assert_same( 'Kraków', $result[0]['label'] );
			}
		);

		it(
			'returns a WP_Error when nothing matched',
			function () {
				slosm_queue_photon( array() );

				$result = slosm_geocoder()->suggest( 'qqqqqq' );

				assert_same( 'slosm_suggest_no_results', slosm_error_code( $result ) );
			}
		);

		it(
			'returns a WP_Error when every feature had to be dropped',
			function () {
				slosm_queue_photon( array( slosm_photon_feature( 'x', 'y', array( 'name' => 'Nowhere' ) ) ) );

				$result = slosm_geocoder()->suggest( 'qqqqqq' );

				assert_same( 'slosm_suggest_no_results', slosm_error_code( $result ) );
			}
		);

		it(
			'drops a feature with no geometry at all',
			function () {
				slosm_queue_photon(
					array(
						array( 'type' => 'Feature', 'properties' => array( 'name' => 'Nowhere' ) ),
						'not a feature at all',
						slosm_photon_feature( 19.9450, 50.0647, array( 'name' => 'Kraków' ) ),
					)
				);

				$result = slosm_geocoder()->suggest( 'k' );

				assert_same( 1, count( $result ) );
				assert_same( 'Kraków', $result[0]['label'] );
			}
		);

		it(
			'drops a feature whose coordinates are a single number',
			function () {
				slosm_queue_photon(
					array(
						slosm_photon_feature( 21.0122, null, array( 'name' => 'Half a point' ) ),
						slosm_photon_feature( 19.9450, 50.0647, array( 'name' => 'Kraków' ) ),
					)
				);

				$result = slosm_geocoder()->suggest( 'k' );

				assert_same( 1, count( $result ) );
				assert_same( 'Kraków', $result[0]['label'] );
			}
		);

		it(
			'drops a feature with a short coordinate list, and raises no warning doing it',
			function () {
				slosm_queue_photon(
					array(
						array(
							'type'       => 'Feature',
							'geometry'   => array(
								'type'        => 'Point',
								'coordinates' => array( 21.0122 ),
							),
							'properties' => array( 'name' => 'Half a point' ),
						),
						slosm_photon_feature( 19.9450, 50.0647, array( 'name' => 'Kraków' ) ),
					)
				);

				/*
				 * The warning is the whole case. Reading $coordinates[1] on a
				 * one-element list produces the same dropped feature either
				 * way, so a result assertion alone cannot tell a guarded read
				 * from an unguarded one — it survives deleting the isset().
				 * What differs is an "Undefined array key" warning, which on a
				 * site with WP_DEBUG_DISPLAY on is printed into the body of the
				 * json response these suggestions are returned in and breaks
				 * the parse at the browser end.
				 */
				set_error_handler(
					static function ( $errno, $message, $file, $line ) {
						throw new ErrorException( $message, 0, $errno, $file, $line );
					}
				);

				try {
					$result = slosm_geocoder()->suggest( 'k' );
				} finally {
					restore_error_handler();
				}

				assert_same( 1, count( $result ) );
				assert_same( 'Kraków', $result[0]['label'] );
			}
		);

		it(
			'refetches rather than returning an empty list out of the cache',
			function () {
				// Nothing ever caches an empty list, so an empty array under the
				// key came from somewhere else and is not an answer.
				$geocoder = slosm_geocoder();
				set_transient( $geocoder->cache_key( Geocoder::SERVICE_PHOTON, 'warszawa' ), array(), 600 );
				slosm_queue_photon( array( slosm_photon_feature() ) );

				$result = $geocoder->suggest( 'warszawa' );

				assert_same( 1, slosm_requests() );
				assert_same( 1, count( $result ) );
			}
		);

		it(
			'returns a WP_Error when the body is not a feature collection',
			function () {
				slosm_queue_response( '[]' );

				$result = slosm_geocoder()->suggest( 'warszawa' );

				assert_same( 'slosm_suggest_bad_json', slosm_error_code( $result ) );
			}
		);

		it(
			'gives suggestion failures their own error codes',
			function () {
				$GLOBALS['slosm_stub']['http_queue'][] = new WP_Error( 'http_request_failed', 'Could not resolve host' );

				$result = slosm_geocoder()->suggest( 'warszawa' );

				assert_same( 'slosm_suggest_http', slosm_error_code( $result ) );
			}
		);

		it(
			'refuses an empty suggestion query without making a request',
			function () {
				$result = slosm_geocoder()->suggest( '   ' );

				assert_same( 'slosm_suggest_empty_query', slosm_error_code( $result ) );
				assert_same( 0, slosm_requests() );
			}
		);

		it(
			'does not reach the network for a repeat suggestion',
			function () {
				slosm_queue_photon( array( slosm_photon_feature() ) );

				$geocoder = slosm_geocoder();
				$geocoder->suggest( '  Warszawa ' );
				$geocoder->suggest( 'warszawa' );

				assert_same( 1, slosm_requests() );
			}
		);

		it(
			'caches suggestions for a day, not for the geocoder lifetime',
			function () {
				// Geocode queries are whole addresses saved by an editor:
				// bounded and stable. Suggestion queries are every prefix every
				// visitor types, so a thirty-day lifetime would leave one
				// wp_options row per prefix on a site with no object cache, and
				// WordPress only sweeps expired transients on a daily cron.
				slosm_queue_photon( array( slosm_photon_feature() ) );

				$geocoder = slosm_geocoder();
				$geocoder->suggest( 'warszawa' );

				$key = $geocoder->cache_key( Geocoder::SERVICE_PHOTON, 'warszawa' );

				assert_same( DAY_IN_SECONDS, $GLOBALS['slosm_stub']['transients'][ $key ]['ttl'] );
			}
		);

		it(
			'does not cache a failed suggestion',
			function () {
				slosm_queue_response( 'Too Many Requests', 429 );

				$geocoder = slosm_geocoder();
				$geocoder->suggest( 'warszawa' );

				assert_false( slosm_cached( $geocoder->cache_key( Geocoder::SERVICE_PHOTON, 'warszawa' ) ) );
				slosm_assert_success_is_cached( $geocoder, Geocoder::SERVICE_PHOTON );
			}
		);

		it(
			'keeps suggestions out of the geocoder cache namespace',
			function () {
				// Both services answer the same question about the same string
				// and hand back different shapes. One namespace would let a
				// geocode read a suggestion list, or the reverse.
				slosm_queue_nominatim( array( slosm_nominatim_hit() ) );
				slosm_queue_photon( array( slosm_photon_feature() ) );

				$geocoder = slosm_geocoder();
				$point    = $geocoder->geocode( 'Warszawa' );
				$list     = $geocoder->suggest( 'Warszawa' );

				assert_same( 2, slosm_requests() );
				assert_same( 'Warszawa, Polska', $point['label'] );
				assert_same( 1, count( $list ) );
				assert_contains( 'photon', slosm_last_url() );

				// The assertions above survive a shared namespace, because the
				// shape guards make each service reject the other's entry and
				// fetch again. What does not survive is this: under one key the
				// suggestion has just overwritten the point, so the repeat
				// geocode would have to go back to the network — and no response
				// is queued for it.
				$repeat = $geocoder->geocode( 'Warszawa' );

				assert_same( 2, slosm_requests() );
				assert_same( 'Warszawa, Polska', $repeat['label'] );
			}
		);

		it(
			'sends no country to Photon, which has no parameter for one',
			function () {
				slosm_queue_photon( array( slosm_photon_feature() ) );

				slosm_geocoder()->suggest( 'warszawa', 'pl' );

				assert_contains( 'q=warszawa', slosm_last_url() );
				assert_same( false, strpos( slosm_last_url(), 'countrycodes' ) );
				// Not under any other name either. The default endpoint carries
				// no 'pl' of its own, so this is the country or nothing.
				assert_same( false, strpos( slosm_last_url(), 'pl' ) );
			}
		);

		it(
			'still keeps two countries in two suggestion cache entries',
			function () {
				// The country narrows nothing upstream today, so these two
				// requests are identical and one of them is waste. That is
				// accepted on purpose: the day a country bias is added to the
				// Photon request, a key without the country in it would serve
				// one country's list for another, silently.
				slosm_queue_photon( array( slosm_photon_feature() ) );
				slosm_queue_photon( array( slosm_photon_feature() ) );

				$geocoder = slosm_geocoder();
				$geocoder->suggest( 'warszawa', 'pl' );
				$geocoder->suggest( 'warszawa', 'de' );

				assert_same( 2, slosm_requests() );
			}
		);

		it(
			'reads the Photon endpoint from its own setting',
			function () {
				$GLOBALS['slosm_stub']['options']['slosm_settings'] = array( 'suggest_endpoint' => 'https://photon.example.test/api' );
				slosm_queue_photon( array( slosm_photon_feature() ) );

				slosm_geocoder()->suggest( 'warszawa' );

				assert_contains( 'https://photon.example.test/api?', slosm_last_url() );
			}
		);

		it(
			'asks Photon for a bounded number of suggestions',
			function () {
				slosm_queue_photon( array( slosm_photon_feature() ) );

				slosm_geocoder()->suggest( 'warszawa' );

				// The literal, not the constant. Asserting against the constant
				// agrees with any value the constant is changed to, which is the
				// one thing this case is for.
				assert_contains( 'limit=5', slosm_last_url() );
			}
		);

		it(
			'rate limits suggestions as well',
			function () {
				slosm_queue_photon( array( slosm_photon_feature() ) );
				slosm_queue_photon( array( slosm_photon_feature( 19.9450, 50.0647, array( 'name' => 'Kraków' ) ) ) );

				$geocoder = slosm_geocoder();
				$geocoder->suggest( 'warszawa' );
				$geocoder->suggest( 'krakow' );

				$sleeps = $GLOBALS['slosm_stub']['sleeps'];

				assert_same( 1, count( $sleeps ), 'sleeps: ' . var_export( $sleeps, true ) );
				assert_close( 1.0, $sleeps[0], 0.0001 );
			}
		);
	}
);

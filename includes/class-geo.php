<?php
/**
 * Distance and bounding-box maths.
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Geo' ) ) {

	/**
	 * The two pieces of spherical arithmetic a proximity search needs.
	 *
	 * Static, stateless, and it calls nothing from WordPress. There is no
	 * instance to build and no configuration to hold: the same inputs give the
	 * same answer on any site, which is what makes both methods testable with
	 * arithmetic alone.
	 *
	 * Why this is PHP and not SQL
	 * ---------------------------
	 * The design document originally put the haversine expression in the
	 * `ORDER BY` of the search query. It does not any more, for two reasons.
	 *
	 * The first is that the query could not have been fast anyway. Coordinates
	 * live in post meta, and `meta_value` is a string column: any index on it is
	 * an index of text, so a trigonometric expression over a cast of that column
	 * cannot use one. Every candidate row gets its sine and cosine computed by
	 * MySQL, on every search, and then sorted — the work is identical to doing it
	 * in PHP, minus the ability to see it.
	 *
	 * The second is that maths in SQL is maths nobody can test. This file needs
	 * no database, no fixtures and no WordPress; the suite checks it against
	 * figures computed by a different formula on a different earth model.
	 *
	 * So Task 7 splits the work: bounding_box() produces four plain numbers for a
	 * `BETWEEN` on `meta_value`; distance() then measures the candidates exactly,
	 * in PHP, and the sorting and limiting happen there.
	 *
	 * What the prefilter does not do is use an index — for the same reason the
	 * sort could not. Cast `meta_value` to a number and the index on the text is
	 * dead; leave it uncast and MySQL coerces the column, which kills it just the
	 * same. Comparing it as text is not an option at all, since '9.5' sorts above
	 * '10.5'. The box earns its place by cutting the number of rows that cross
	 * into PHP and the memory they occupy, not by making the scan cheaper.
	 *
	 * The sphere, and what it costs
	 * -----------------------------
	 * Both methods treat the earth as a sphere. It is not one — WGS-84 puts it
	 * 42.77 km wider across the equator than from pole to pole, 12756.27 km
	 * against 12713.51 — so haversine differs from a true geodesic by up to
	 * roughly 0.5%: on the Warsaw–Kraków pair in the tests, 251.98 km against
	 * Vincenty's 252.16 on the ellipsoid. Two hundred metres in two hundred and
	 * fifty kilometres.
	 *
	 * That is far inside the error a store locator already carries. A geocoded
	 * address is a point on a building or a street segment, the visitor's own
	 * position came from a browser that may be a kilometre out, and the answer is
	 * shown as "4.2 km away" next to a driving link. Vincenty's formula would
	 * cost an iterative solve per location, on every row, for precision no user
	 * of this plugin can perceive.
	 */
	final class Geo {

		/**
		 * Mean earth radius in kilometres.
		 *
		 * @var float
		 */
		public const EARTH_RADIUS_KM = 6371.0;

		/**
		 * The same radius in miles.
		 *
		 * @var float
		 */
		public const EARTH_RADIUS_MI = 3958.8;

		/**
		 * The unit strings this class understands, for callers that must reject
		 * everything else.
		 *
		 * Geo parses nothing: distance() and bounding_box() take 'mi' or fall
		 * back to kilometres, and neither reports a bad value. Rejecting one is
		 * the caller's job — the settings sanitiser, the shortcode handler, the
		 * REST validate_callback — and this is the list all three check against,
		 * so "mi is the only accepted spelling" is stated once instead of being
		 * remembered separately in three files.
		 *
		 * The stakes are higher than "an unknown unit measures in kilometres"
		 * makes it sound. A unit that silently falls back shrinks the search
		 * radius and the reported distances by the same factor, so the box and
		 * the numbers beside it agree with each other perfectly. Nothing
		 * downstream can detect it; the results are simply a smaller circle than
		 * the visitor asked for.
		 *
		 * @var string[]
		 */
		public const UNITS = array( 'km', 'mi' );

		/**
		 * Kilometres in one degree of latitude, on the sphere this class measures on.
		 *
		 * Derived, never quoted. The first version of this file used 111.32, a
		 * figure from a table, and it was wrong in the one direction that loses
		 * locations: a degree of latitude on a sphere of radius EARTH_RADIUS_KM is
		 * 111.194927 km, so dividing by 111.32 produced a box 0.11% *smaller* than
		 * the circle distance() measures — 112 m short at a 100 km radius — and a
		 * store at the edge of the radius was cut by the SQL prefilter before
		 * anything could measure it. Silently, since the prefilter is where rows
		 * stop existing.
		 *
		 * 111.32 is not even a meridional figure: 111.3195 is the WGS-84
		 * *equatorial degree of longitude*, a different quantity that happens to
		 * land nearby. The lesson is not "use a better table value" — it is that
		 * an ellipsoid constant has no business in a spherical model. What matters
		 * is that the box agrees with distance(), because distance() decides the
		 * final answer, and the only way to guarantee that is to derive this from
		 * the same radius distance() uses.
		 *
		 * @var float
		 */
		public const KM_PER_DEGREE = self::EARTH_RADIUS_KM * M_PI / 180.0;

		/**
		 * How much wider than the exact circle the box is drawn.
		 *
		 * A tenth of a percent, so nothing can arrive at a box that falls short
		 * by a rounding step. The exact box touches the circle at four points,
		 * which means a location sitting on the radius is decided by the last bit
		 * of a sine — and the two sides of that decision are not equal: a box a
		 * metre too wide costs one candidate row that distance() then discards,
		 * while a box a metre too narrow costs a location that exists and never
		 * appears.
		 *
		 * @var float
		 */
		public const BOX_MARGIN = 1.001;

		/**
		 * The cosine below which bounding_box() stops dividing by it.
		 *
		 * 1.0e-6 is a latitude within 0.000057 degrees of a pole, about six
		 * metres. See bounding_box() for why the guard is on the divisor rather
		 * than on the latitude.
		 *
		 * @var float
		 */
		public const MIN_COSINE = 1.0e-6;

		/**
		 * No instances.
		 *
		 * Everything here is a pure function of its arguments, so an object would
		 * carry nothing and mean nothing. Private rather than merely unused, so
		 * that "new Geo()" is an error at the moment somebody writes it.
		 */
		private function __construct() {
		}

		/**
		 * Great-circle distance between two points, by the haversine formula.
		 *
		 * Do not tidy the clamp inside asin() away, and do not trust the usual
		 * story about it either. Both are worth stating precisely, because the
		 * measurements do not quite match the folklore.
		 *
		 * What is true: $a genuinely exceeds 1. For 45°N, 21.0122°E against its
		 * antipode it comes to 1.0000000000000002, because sin() and cos() are
		 * each correctly rounded and their products are not. asin() of anything
		 * above 1 is outside its domain and returns NAN, and NAN is the worst
		 * failure this file could produce, because it is silent: it compares
		 * false against every threshold, so a "within 10 km" filter drops the
		 * location and a sort puts it somewhere arbitrary. No warning, no error,
		 * just a shop that has vanished from the results.
		 *
		 * What was written here and was FALSE, corrected rather than quietly
		 * deleted, because the mistake is more instructive than the fact: this
		 * docblock used to say the clamp "is not reached on the two binaries
		 * this plugin is tested on", on the strength of a sweep of 400,000
		 * "near-antipodal" pairs that found no sqrt( $a ) above 1, and
		 * concluded that deleting the clamp left the whole suite green.
		 *
		 * The sweep was wrong. It walked *exact* antipodes — lat2 = -lat1,
		 * lng2 = lng1 ± 180 — and exact antipodes genuinely do give zero hits,
		 * on 8.2, on 8.5 and on V8, at full precision and rounded to 6, 8, 10
		 * and 12 decimal places. That is what made the result look solid. The
		 * overshoot needs a pair perturbed off the antipode by around 1e-13
		 * degrees, and then it is 230 hits per 400,000 on both binaries here
		 * (305 on V8), every one of them NAN without the clamp.
		 *
		 * So the clamp is load-bearing, not insurance, and the suite now kills
		 * the mutation. 'refuses to hand asin() a value above 1' pins a pair
		 * that really does overshoot on both binaries:
		 *
		 *   66.285632120578384, -161.37757434573842,
		 *  -66.285632120578427,   18.622425654261534
		 *
		 * giving $a = 1.00000000000000044409, sqrt( $a ) = 1.00000000000000022204,
		 * 20015.086796020572 km clamped and NAN without. The pair is
		 * platform-specific — PHP's libm and V8's round $a differently, so this
		 * one comes out to exactly 1 under V8 and the JavaScript suite carries
		 * its own — and the case says so, so that a future libm which rounds it
		 * away fails loudly instead of testing nothing.
		 *
		 * assert_close() refusing to compare NAN is the second line of defence
		 * and is what makes the failure audible rather than merely present.
		 *
		 * An unrecognised unit measures in kilometres. There is no third unit and
		 * no parsing: the caller — settings, a shortcode attribute, a REST
		 * parameter — is where 'Miles' or 'mi ' becomes 'mi', because that is
		 * where a bad value can be reported to the person who typed it.
		 *
		 * @param float  $lat1 Latitude of the first point, in degrees.
		 * @param float  $lng1 Longitude of the first point, in degrees.
		 * @param float  $lat2 Latitude of the second point, in degrees.
		 * @param float  $lng2 Longitude of the second point, in degrees.
		 * @param string $unit 'mi' for miles; anything else means kilometres.
		 * @return float Distance in the requested unit.
		 */
		public static function distance( float $lat1, float $lng1, float $lat2, float $lng2, string $unit = 'km' ): float {
			$radius = self::earth_radius( $unit );

			$d_lat = deg2rad( $lat2 - $lat1 );
			$d_lng = deg2rad( $lng2 - $lng1 );

			$a = sin( $d_lat / 2 ) ** 2
				+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lng / 2 ) ** 2;

			return $radius * 2 * asin( min( 1.0, sqrt( $a ) ) );
		}

		/**
		 * A latitude/longitude rectangle that contains a search circle.
		 *
		 * Four numbers — min_lat, max_lat, min_lng, max_lng — for a `BETWEEN` in
		 * SQL. The box is a superset of the circle and must stay one: its corners
		 * lie outside the radius, so it hands back candidates that distance()
		 * then rejects. Returning too many is cheap. Returning too few is a
		 * location that exists and never appears, with no error and no trace,
		 * because the prefilter is where rows stop existing.
		 *
		 * That invariant is not free, and both halves of it were wrong once. See
		 * KM_PER_DEGREE for the latitude half. This is the longitude half.
		 *
		 * Longitude: the exact half-width, not the small-angle one
		 * --------------------------------------------------------
		 * Meridians converge, so a degree of longitude is 111 km at the equator
		 * and nothing at all at the pole. The obvious width is the latitude delta
		 * divided by cos( lat ), and it is the small-angle approximation: about
		 * 0.12% narrow at mid-latitudes, which loses the same edge locations
		 * KM_PER_DEGREE did, and badly wrong approaching a pole.
		 *
		 * The exact half-width of a spherical cap of angular radius r/R centred
		 * at latitude φ is asin( sin( r / R ) / cos( φ ) ), and the interesting
		 * part is when that ratio reaches 1: there is no such angle, because the
		 * cap has swallowed the pole and spans every meridian. So the ratio is
		 * the test. At a 100 km radius it reaches 1 at latitude 89.1007, where
		 * the approximate form was still handing back a confident, finite box
		 * roughly 64 degrees wide, and only widened to the full range at 89.7141
		 * — a 0.61 degree band in which every location on the far side of the
		 * pole was dropped.
		 *
		 * One trap worth naming, because it looks like the tidy version of this:
		 * clamping the ratio and taking asin( 1.0 ) does not produce that
		 * fallback. It produces a half-width of 90 degrees, which is a 180 degree
		 * span — half the world, confidently. The check has to come before the
		 * asin(), not inside it.
		 *
		 * Why the guard is on the cosine and not on the latitude
		 * ------------------------------------------------------
		 * cos( deg2rad( 90 ) ) is 6.123e-17, not zero — deg2rad( 90 ) is the
		 * nearest double to pi/2, not pi/2 — so a `0.0 === $cosine` check never
		 * fires, at the pole or anywhere else. Testing the divisor for smallness
		 * is the only check that fires where the arithmetic actually breaks down.
		 *
		 * The saturating ratio above already catches every case where the cap is
		 * wide enough to reach the pole, so what is left for this guard is the
		 * case where the numerator is tiny too and the ratio stays small:
		 *
		 * - Exactly zero would throw. Since PHP 8, division by 0.0 raises
		 *   DivisionByZeroError rather than yielding INF, so an unguarded divide
		 *   is a fatal error on a front-end search. Today no input reaches exactly
		 *   zero; MIN_COSINE means none ever has to.
		 * - A radius of zero at the pole produces something that looks reasonable
		 *   and is not: 0 / 6.123e-17 is 0, a ratio of 0, and a box one point wide
		 *   at the north pole that quietly matches nothing.
		 *
		 * Below MIN_COSINE the answer is the full ±180 range, which is the honest
		 * one: at the pole every meridian is underfoot, so no narrower box is
		 * correct. That is a latitude within about six metres of the pole, and
		 * everything short of it is the ratio's business.
		 *
		 * The antimeridian: the same fallback, decided on purpose
		 * -------------------------------------------------------
		 * A box centred at 179.9°E with a 100 km radius reaches 180.8°E, which is
		 * not a longitude. Three things could happen, and this method picks the
		 * third:
		 *
		 * - Emit min_lng 180.8 and max_lng -179.2 — a wrapped box, correct only
		 *   for a reader who knows to wrap. `BETWEEN 180.8 AND -179.2` matches no
		 *   row at all, so Task 7 would inherit a search that silently returns
		 *   nothing in the Pacific.
		 * - Emit two ranges and have the query OR them. Correct, and it makes
		 *   every caller handle a shape they need only in one place on earth.
		 * - Widen to ±180, and let the exact distance in PHP discard the extra
		 *   candidates. The prefilter stops filtering longitude near the
		 *   antimeridian; it stays correct, and the latitude band still does most
		 *   of the cutting.
		 *
		 * The same clause catches a radius wide enough to wrap the globe from
		 * anywhere, and a longitude the caller passed outside ±180.
		 *
		 * Radius: negative and NAN
		 * ------------------------
		 * Both become zero, which boxes a point and finds nothing. The radius is
		 * the one argument that arrives from outside — a shortcode attribute, a
		 * REST parameter, a settings field — and unguarded, -25 turns the box
		 * inside out (min above max, matching nothing but saying nothing either)
		 * and NAN gives it NaN edges. Finding nothing for a nonsensical radius is
		 * the answer that is at least true.
		 *
		 * NAN needs its own test: max( 0.0, NAN ) is NAN on both binaries this
		 * suite runs on, because max() picks by comparison and every comparison
		 * against NAN is false, so the guard would pass it straight through.
		 *
		 * Latitude gets no such treatment beyond the clamp, and deliberately: a
		 * NAN latitude would have to become a real place, and inventing 0,0 is
		 * precisely what Store::from_array() refuses to do with an unreadable
		 * coordinate. Latitudes reach this method from a Store, which has already
		 * turned anything unreadable into null.
		 *
		 * @param float  $lat    Centre latitude in degrees; clamped to ±90.
		 * @param float  $lng    Centre longitude in degrees.
		 * @param float  $radius Search radius in $unit; negative and NAN become zero.
		 * @param string $unit   'mi' for miles; anything else means kilometres.
		 * @return array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}
		 */
		public static function bounding_box( float $lat, float $lng, float $radius, string $unit = 'km' ): array {
			$lat    = max( -90.0, min( 90.0, $lat ) );
			$radius = is_nan( $radius ) ? 0.0 : max( 0.0, $radius );

			// Miles become kilometres through the class's own two radii rather
			// than a separate conversion constant, so the box and distance() can
			// never come to disagree about the length of a mile. For 'km' the
			// factor is exactly 1.0.
			$radius_km = $radius * ( self::EARTH_RADIUS_KM / self::earth_radius( $unit ) );
			$lat_delta = ( $radius_km / self::KM_PER_DEGREE ) * self::BOX_MARGIN;

			// Latitude is clamped, not wrapped: a box that ran over the pole
			// would come back down the far side, and 91 degrees north is not a
			// place. Longitude at that point spans everything, which the checks
			// below arrive at on their own.
			$box = array(
				'min_lat' => max( -90.0, $lat - $lat_delta ),
				'max_lat' => min( 90.0, $lat + $lat_delta ),
				'min_lng' => -180.0,
				'max_lng' => 180.0,
			);

			$cosine = cos( deg2rad( $lat ) );

			if ( self::MIN_COSINE > $cosine ) {
				return $box;
			}

			$angular = $radius_km / self::EARTH_RADIUS_KM;

			// Past a quarter of the way around the globe the cap contains a pole
			// whatever its centre, since no point is more than 90 degrees from
			// the nearer one — so it spans every meridian and the full range
			// above is the answer.
			//
			// This check is not only about enormous radii being obviously silly.
			// sin() turns over at a quarter circumference and goes negative past
			// a half, so without it the ratio below *shrinks* as the radius
			// grows: 20000 km would give a box 0.14 degrees wide and 40000 km one
			// that reads backwards. The divide-by-cosine form this replaced grew
			// monotonically and was saved by the ±180 clause; this one has to say
			// so itself.
			if ( M_PI / 2 <= $angular ) {
				return $box;
			}

			// How far the cap reaches in longitude, as a ratio that saturates at
			// the pole. At 1 it has swallowed the pole and spans every meridian,
			// and there is no asin() of it to take.
			$reach = sin( $angular ) / $cosine;

			if ( 1.0 <= $reach ) {
				return $box;
			}

			$lng_delta = rad2deg( asin( $reach ) ) * self::BOX_MARGIN;
			$min_lng   = $lng - $lng_delta;
			$max_lng   = $lng + $lng_delta;

			if ( -180.0 > $min_lng || 180.0 < $max_lng ) {
				return $box;
			}

			$box['min_lng'] = $min_lng;
			$box['max_lng'] = $max_lng;

			return $box;
		}

		/**
		 * The earth's radius in the requested unit.
		 *
		 * The one place either method decides what a unit string means, so the
		 * two cannot drift apart. Named for the earth's radius rather than just
		 * "radius" because bounding_box() holds a search radius one token away,
		 * and two different radii sharing a name in the same expression is how
		 * the wrong one gets used.
		 *
		 * @param string $unit 'mi' for miles; anything else means kilometres.
		 * @return float
		 */
		private static function earth_radius( string $unit ): float {
			return 'mi' === $unit ? self::EARTH_RADIUS_MI : self::EARTH_RADIUS_KM;
		}
	}
}
